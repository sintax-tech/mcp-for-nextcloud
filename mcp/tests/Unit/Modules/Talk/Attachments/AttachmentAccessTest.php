<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk\Attachments;

use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The single rule a draft and a confirmed quote share: an attachment may only be quoted when it is a room share
 * of the very conversation the card would land in. Everything else is the same refusal, so the module never
 * reveals that an id exists somewhere else.
 */
class AttachmentAccessTest extends TestCase {
    private IShareManager&MockObject $shareManager;
    private AttachmentAccess $access;

    protected function setUp(): void {
        parent::setUp();
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->access = new AttachmentAccess($this->shareManager, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::openGuard($this), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }

    public function testARoomShareOfThisConversationIsAccepted(): void {
        $this->givenShare(IShare::TYPE_ROOM, 'abcd', true);

        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);

        $this->addToAssertionCount(1);
    }

    /**
     * Regression for the real-world failure: the share manager resolves ids as "<provider>:<id>" and throws on a
     * bare number, so talk_quote_file answered "attachment not found" for the very id talk_attach_file returned.
     */
    public function testTheBareIdFromReadMessagesIsLookedUpAsAFullRoomShareId(): void {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('77');
        $share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn('abcd');
        $share->method('getNode')->willReturn($this->createMock(\OCP\Files\File::class));
        $this->shareManager->method('getShareById')->willReturnCallback(
            static function (string $id) use ($share): IShare {
                if ($id !== 'ocRoomShare:77') {
                    throw new RuntimeException('Invalid share id: ' . $id);
                }

                return $share;
            },
        );

        $this->assertSame($share, $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77));
    }

    public function testAnIdThatAlreadyHasAProviderIsNotPrefixedAgain(): void {
        $this->assertSame('ocRoomShare:77', \OCA\Mcp\Platform\FileAccess\References\RoomShareId::full('ocRoomShare:77'));
        $this->assertSame('ocRoomShare:77', \OCA\Mcp\Platform\FileAccess\References\RoomShareId::full(77));
    }

    public function testTheValidatedShareComesBackSoTheDraftCanNameTheFile(): void {
        $this->givenShare(IShare::TYPE_ROOM, 'abcd', true);

        $share = $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);

        $this->assertSame(77, (int)$share->getId());
    }

    public function testTheDescriptionNamesTheFileTheCardWillCarry(): void {
        $node = $this->createMock(File::class);
        $node->method('getName')->willReturn('relatorio.pdf');
        $node->method('getSize')->willReturn(2048);
        $node->method('getMimeType')->willReturn('application/pdf');
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willReturn($node);

        // An id alone tells the user nothing about what is about to be cited in their name.
        $this->assertSame(
            ['attachmentId' => 77, 'name' => 'relatorio.pdf', 'size' => 2048, 'mimeType' => 'application/pdf'],
            $this->access->describe($share),
        );
    }

    public function testAFileDeletedSinceTheShareIsReportedWithoutAName(): void {
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willThrowException(new NotFoundException());

        $description = $this->access->describe($share);

        // Inventing a name for a file that is gone is worse than reporting none: the send will fail on its own.
        $this->assertSame(['attachmentId' => 77, 'name' => null, 'size' => null, 'mimeType' => null], $description);
    }

    public function testAFolderShareHasNoMimeType(): void {
        $folder = $this->createMock(Folder::class);
        $folder->method('getName')->willReturn('fotos');
        $folder->method('getSize')->willReturn(null);
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willReturn($folder);

        $this->assertSame(
            ['attachmentId' => 77, 'name' => 'fotos', 'size' => null, 'mimeType' => null],
            $this->access->describe($share),
        );
    }

    public function testTheShareIsOnlyReadAndNeverWritten(): void {
        $this->shareManager->expects($this->once())
            ->method('getShareById')
            ->with('ocRoomShare:77', 'alice')
            ->willReturn($this->givenShare(IShare::TYPE_ROOM, 'abcd', true));
        // Reading the share is all this may do: creating one here would publish the attachment a second time.
        $this->shareManager->expects($this->never())->method('createShare');
        $this->shareManager->expects($this->never())->method('newShare');

        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }

    public function testAShareOfAnotherConversationIsRefused(): void {
        $this->givenShare(IShare::TYPE_ROOM, 'zzzz');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::attachmentNotFound());
        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }

    public function testAShareThatIsNotOfTheConversationKindIsRefused(): void {
        $this->givenShare(IShare::TYPE_LINK, 'abcd');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::attachmentNotFound());
        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }


    public function testSomethingTheUserCannotSeeIsRefusedWithoutLeakingTheCause(): void {
        $this->shareManager->method('getShareById')
            ->willThrowException(new RuntimeException('Share 77 is not visible to alice at 10.0.0.9'));

        try {
            $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
            $this->fail('An attachment the user cannot see must be refused.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::attachmentNotFound(), $e->getMessage());
            $this->assertStringNotContainsString('not visible', $e->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $e->getMessage());
            // The cause is kept for the log only.
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }
    public function testHiddenAttachmentIsRefusedAsNotFound(): void {
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(777);
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $node->method('getOwner')->willReturn($owner);
        $folder = $this->createMock(\OCP\Files\Folder::class);
        $folder->method('getId')->willReturn(1);
        $folder->method('getById')->with(777)->willReturn([$node]);
        $node->method('getParent')->willReturn($folder);
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willReturn($node);

        $config = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
        $config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagMapper->method('getTagIdsForObjects')->willReturn(['777' => ['999']]);
        $guard = new \OCA\Mcp\Access\Visibility\VisibilityGuard($config->mock($this), $tagMapper, $root);

        $access = new AttachmentAccess($this->shareManager, $guard, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::attachmentNotFound());
        $access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }

    public function testDescribeOmitsInfoWhenNodeIsHidden(): void {
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(777);
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $node->method('getOwner')->willReturn($owner);
        $folder = $this->createMock(\OCP\Files\Folder::class);
        $folder->method('getId')->willReturn(1);
        $folder->method('getById')->with(777)->willReturn([$node]);
        $node->method('getParent')->willReturn($folder);
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($folder);
        $node->method('getName')->willReturn('secret.pdf');
        $node->method('getSize')->willReturn(2048);
        $node->method('getMimeType')->willReturn('application/pdf');
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willReturn($node);

        $access = new AttachmentAccess($this->shareManager, $this->givenGuard(['777' => ['999']], $root), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));

        $desc = $access->describe($share);

        $this->assertSame(['attachmentId' => 77, 'name' => null, 'size' => null, 'mimeType' => null], $desc);
    }

    /**
     * An attachment may only pass when its node is resolved and seen as visible by the guard: a resolution
     * failure can hide a missing tag check, so it is the very same refusal as an unknown id, never an acceptance.
     */
    public function testAnAttachmentWhoseNodeCannotBeCheckedIsRefusedAsNotFound(): void {
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willThrowException(new RuntimeException('storage of share 77 is offline'));

        $access = new AttachmentAccess($this->shareManager, $this->givenGuard(), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));

        try {
            $access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
            $this->fail('An attachment whose node cannot be checked against the hidden tags must be refused.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessages::attachmentNotFound(), $e->getMessage());
            $this->assertStringNotContainsString('storage', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function testAVisibleAttachmentIsStillAcceptedWhenTheGuardIsActive(): void {
        $node = $this->createMock(File::class);
        $node->method('getId')->willReturn(777);
        // A node of the user's own storage, not a share mount: the guard reads its mounted chain only.
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);
        $node->method('getStorage')->willReturn($storage);
        $share = $this->givenShare(IShare::TYPE_ROOM, 'abcd');
        $share->method('getNode')->willReturn($node);

        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $node->method('getParent')->willReturn($root);
        $access = new AttachmentAccess($this->shareManager, $this->givenGuard([], $root), \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));

        $this->assertSame($share, $access->requireRoomShareOf($this->givenConversation(), 'alice', 77));
    }


    private function givenShare(int $shareType, string $sharedWith, bool $withNode = false): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        if ($withNode) {
            $share->method('getNode')->willReturn($this->createMock(\OCP\Files\File::class));
        }
        $share->method('getId')->willReturn('77');
        $share->method('getShareType')->willReturn($shareType);
        $share->method('getSharedWith')->willReturn($sharedWith);
        $this->shareManager->method('getShareById')->willReturn($share);

        return $share;
    }

    private function givenConversation(): Conversation {
        return new Conversation(new AccessRoomStub('abcd'), new AccessParticipantStub());
    }

    /**
     * @param array<string, list<string>> $tagsById tags of each file id
     * @param \OCP\Files\IRootFolder|null $root root the guard resolves a share's owner tree through
     */
    private function givenGuard(array $tagsById = [], ?\OCP\Files\IRootFolder $root = null): \OCA\Mcp\Access\Visibility\VisibilityGuard {
        $config = new \OCA\Mcp\Tests\Unit\InMemoryConfig();
        $config->app['mcp'][\OCA\Mcp\Access\Visibility\VisibilityGuard::CONFIG_KEY] = json_encode(['999']);
        $tagMapper = $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagMapper->method('getTagIdsForObjects')
            ->willReturnCallback(static function (array $ids) use ($tagsById): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[(string)$id] = $tagsById[(string)$id] ?? [];
                }

                return $out;
            });

        return new \OCA\Mcp\Access\Visibility\VisibilityGuard($config->mock($this), $tagMapper, $root);
    }
}

final class AccessRoomStub {
    public function __construct(private string $token) {}

    public function getToken(): string {
        return $this->token;
    }
}

final class AccessParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}