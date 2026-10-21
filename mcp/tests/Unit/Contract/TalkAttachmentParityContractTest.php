<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use DateTime;
use DateTimeImmutable;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters;
use OCA\Mcp\Tests\Support\Platform\FileAccess\FakeTree;
use OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher as ConversationWriter;
use OCA\Mcp\Modules\Talk\Attachments\FileAccessException;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Attachments\UserFileResolver;
use OCA\Mcp\Modules\Talk\Attachments\QuotePlan as WritePreview;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Talk reads and writes follow one reference rule (A.5, D6). The node a room share hands back belongs to the owner's
 * tree and can be clean while the file, where the participant sees it, sits under an excluded folder, or has a second
 * alias there. talk_read_messages, the talk_quote_file draft and its confirmation must then all answer exactly as for
 * an attachment that does not exist, and talk_attach_file must refuse a clean path whose other alias is excluded.
 */
final class TalkAttachmentParityContractTest extends TestCase {
    private const HIDDEN_TAG = '999';
    private const FILE_ID = 4242;
    private const SHARE = 30;
    private const MISSING_SHARE = 404;

    private FakeTree $caller;
    private FakeTree $owner;
    /** @var array<string, list<string>> */
    private array $tags = [];
    private InMemoryConfig $config;
    private VisibilityGuard $guard;
    /** @var list<string> messages the chat manager was asked to publish */
    private array $published = [];
    /** The Files scope step; null is a user without a Files scope. */
    private ?\OCA\Mcp\Platform\FileAccess\References\FileReferenceScope $scope = null;

    /**
     * @return array<string, array{list<string>}> paths at which the participant's tree holds the shared file
     */
    public static function participantViews(): array {
        return [
            'only under an excluded folder' => [['/alice/files/Secret/plan.pdf']],
            'two aliases, one excluded' => [['/alice/files/Docs/plan.pdf', '/alice/files/Secret/plan.pdf']],
        ];
    }

    /** @param list<string> $callerPaths */
    private function given(array $callerPaths): void {
        $this->caller = new FakeTree($this, '/alice/files');
        $secret = $this->caller->addFolder('/alice/files/Secret');
        $this->caller->addFolder('/alice/files/Docs');
        foreach ($callerPaths as $path) {
            $this->caller->addFile($path, 'the plan');
            $this->caller->nodes[$path]['id'] = self::FILE_ID;
        }
        // The owner's tree: the very same file id, on a clean path, with ids of its own for the folders.
        $this->owner = new FakeTree($this, '/owner/files');
        $this->owner->addFile('/owner/files/Public/plan.pdf', 'the plan');
        $this->owner->nodes['/owner/files']['id'] = 9001;
        $this->owner->nodes['/owner/files/Public']['id'] = 9002;
        $this->owner->nodes['/owner/files/Public/plan.pdf']['id'] = self::FILE_ID;
        $this->tags = [(string)$secret => [self::HIDDEN_TAG]];

        $this->config = new InMemoryConfig();
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturnCallback(function (array $ids): array {
            $result = [];
            foreach ($ids as $id) {
                $result[(string)$id] = $this->tags[(string)$id] ?? [];
            }
            return $result;
        });
        $this->guard = new VisibilityGuard($this->config->mock($this), $mapper, $this->caller->filesystemRoot());
        self::assertTrue($this->guard->isVisible($this->owner->node('/owner/files/Public/plan.pdf')), 'the owner node is clean');
    }

    private function shares(): IShareManager {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn((string)self::SHARE);
        $share->method('getShareType')->willReturn(IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn('room1');
        $share->method('getNodeId')->willReturn(self::FILE_ID);
        $share->method('getNode')->willReturnCallback(fn () => $this->owner->node('/owner/files/Public/plan.pdf'));
        $shares = $this->createMock(IShareManager::class);
        $shares->method('getShareById')->willReturnCallback(fn (string $id) => $id === 'ocRoomShare:' . self::SHARE
            ? $share
            : throw new \OCP\Share\Exceptions\ShareNotFound());
        return $shares;
    }

    private function filter(IShareManager $shares): OutputReferenceFilter {
        return OutputReferenceFilters::over($this, $this->caller->filesystemRoot(), $this->guard, $shares, $this->scope);
    }

    private function conversation(): Conversation {
        return new Conversation(new TalkParityRoomStub('room1'), new TalkParityParticipantStub());
    }

    private function chat(array $history = []): object {
        $chat = $this->createMock(TalkParityChat::class);
        $chat->method('getHistory')->willReturn($history);
        $chat->method('addSystemMessage')->willReturnCallback(function ($room, $participant, $actorType, $actorId, string $message): IComment {
            $this->published[] = $message;
            $comment = $this->createMock(IComment::class);
            $comment->method('getId')->willReturn('77');
            return $comment;
        });
        return $chat;
    }

    private function access(IShareManager $shares): AttachmentAccess {
        return new AttachmentAccess($shares, $this->guard, $this->filter($shares));
    }

    private function writer(IShareManager $shares): ConversationWriter {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new DateTime('2026-10-15 12:00:00'));
        return new ConversationWriter(fn () => $this->chat(), static fn () => ['actorUsers' => 'users'], $time, new AttachmentMessage(), $this->access($shares));
    }

    private function preview(IShareManager $shares): WritePreview {
        return new WritePreview($this->access($shares));
    }

    private function readAttachmentId(IShareManager $shares, int $share): ?int {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn('5');
        $comment->method('getVerb')->willReturn('object_shared');
        $comment->method('getMessage')->willReturn((string)json_encode(['message' => 'file_shared', 'parameters' => ['share' => (string)$share]]));
        $comment->method('getActorType')->willReturn('users');
        $comment->method('getActorId')->willReturn('owner');
        $comment->method('getParentId')->willReturn('0');
        $comment->method('getCreationDateTime')->willReturn(new DateTimeImmutable('@1700000000'));
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForReading')->willReturn($this->conversation());
        $reader = new ConversationReader(static fn () => throw new \LogicException('Unused manager'), static fn () => throw new \LogicException('Unused participants'), fn () => $this->chat([$comment]), $resolver, new ActorNames($this->createMock(IUserManager::class)), $this->filter($shares));
        return $reader->readMessages('alice', 'room1', 10)[0]['attachmentId'];
    }

    /** @return string|array the refusal message, or the draft when there was none */
    private function quotePreview(IShareManager $shares, int $share): string|array {
        try {
            return $this->preview($shares)->quote($this->conversation(), 'alice', $share, null);
        } catch (ConversationAccessException $e) {
            return $e->getMessage();
        }
    }

    /** @return string|array the refusal message, or the result when the card was published */
    private function quoteConfirm(IShareManager $shares, int $share): string|array {
        try {
            return $this->writer($shares)->quoteAttachment($this->conversation(), 'alice', $share);
        } catch (ConversationAccessException $e) {
            return $e->getMessage();
        }
    }

    /** @param list<string> $callerPaths */
    #[DataProvider('participantViews')]
    public function testReadPreviewAndConfirmationAnswerAsForAMissingAttachment(array $callerPaths): void {
        $this->given($callerPaths);
        $shares = $this->shares();

        self::assertNull($this->readAttachmentId($shares, self::SHARE));
        self::assertSame($this->readAttachmentId($shares, self::MISSING_SHARE), $this->readAttachmentId($shares, self::SHARE));

        $missing = $this->quotePreview($shares, self::MISSING_SHARE);
        self::assertIsString($missing);
        self::assertSame($missing, $this->quotePreview($shares, self::SHARE), 'the draft carries no id, name, size or type');

        self::assertSame($this->quoteConfirm($shares, self::MISSING_SHARE), $this->quoteConfirm($shares, self::SHARE));
        self::assertSame([], $this->published, 'nothing is republished');
    }

    public function testACleanParticipantViewStillReadsDraftsAndQuotes(): void {
        $this->given(['/alice/files/Docs/plan.pdf']);
        $shares = $this->shares();

        self::assertSame(self::SHARE, $this->readAttachmentId($shares, self::SHARE));
        $draft = $this->quotePreview($shares, self::SHARE);
        self::assertIsArray($draft);
        self::assertSame(['attachmentId' => self::SHARE, 'name' => 'plan.pdf', 'size' => 8, 'mimeType' => 'text/plain'], $draft['draft']['file']);
        $result = $this->quoteConfirm($shares, self::SHARE);
        self::assertIsArray($result);
        self::assertSame(self::SHARE, $result['attachmentId']);
        self::assertCount(1, $this->published);
    }

    public function testWithoutExclusionTheOwnerRuleAloneDecidesAsBefore(): void {
        $this->given(['/alice/files/Secret/plan.pdf']);
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $this->guard->clearCache();
        $shares = $this->shares();

        self::assertSame(self::SHARE, $this->readAttachmentId($shares, self::SHARE));
        self::assertIsArray($this->quotePreview($shares, self::SHARE));
    }

    public function testAttachRefusesACleanPathWhoseOtherAliasIsExcluded(): void {
        $this->given(['/alice/files/Docs/plan.pdf', '/alice/files/Secret/plan.pdf']);
        $resolver = new UserFileResolver($this->caller->filesystemRoot(), $this->guard, $this->filter($this->shares()));

        $refusal = static function (callable $resolve): string {
            try {
                $resolve();
            } catch (FileAccessException $e) {
                return $e->getMessage();
            }
            return 'resolved';
        };
        $missing = $refusal(fn () => $resolver->resolveShareableFile('alice', 'Docs/missing.pdf'));

        self::assertNotSame('resolved', $missing);
        self::assertSame($missing, $refusal(fn () => $resolver->resolveShareableFile('alice', 'Docs/plan.pdf')));
    }

    public function testAttachOfAFileWithoutExcludedAliasStillResolves(): void {
        $this->given(['/alice/files/Docs/plan.pdf']);
        $resolver = new UserFileResolver($this->caller->filesystemRoot(), $this->guard, $this->filter($this->shares()));

        self::assertSame(self::FILE_ID, (int)$resolver->resolveShareableFile('alice', 'Docs/plan.pdf')->getId());
    }

    /**
     * V9 with the real Files scope (B5): a file only outside alice's Files scope, untagged, is read, quoted and attached
     * exactly like a missing one, in the plan and in the confirmation; nothing is published.
     */
    public function testAFileOnlyOutsideTheFilesScopeAnswersAsAMissingAttachment(): void {
        $this->given(['/alice/files/Docs/plan.pdf']);
        $selected = [(string)$this->caller->nodes['/alice/files/Secret']['id']];
        $this->scope = new \OCA\Mcp\Access\ResourceScope\FilesReferenceScope(\OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes::over(
            $this, $this->guard, $this->caller->filesystemRoot(), static fn (): array => $selected));
        $shares = $this->shares();

        self::assertNull($this->readAttachmentId($shares, self::SHARE));
        self::assertSame($this->quotePreview($shares, self::MISSING_SHARE), $this->quotePreview($shares, self::SHARE));
        self::assertSame($this->quoteConfirm($shares, self::MISSING_SHARE), $this->quoteConfirm($shares, self::SHARE));
        $resolver = new UserFileResolver($this->caller->filesystemRoot(), $this->guard, $this->filter($shares));
        $refusal = static function (callable $resolve): string {
            try {
                $resolve();
            } catch (FileAccessException $e) {
                return $e->getMessage();
            }
            return 'resolved';
        };
        self::assertNotSame('resolved', $refusal(fn () => $resolver->resolveShareableFile('alice', 'Docs/plan.pdf')));
        self::assertSame($refusal(fn () => $resolver->resolveShareableFile('alice', 'Docs/missing.pdf')),
            $refusal(fn () => $resolver->resolveShareableFile('alice', 'Docs/plan.pdf')));
        self::assertSame([], $this->published, 'nothing is published');

        $selected = [(string)$this->caller->nodes['/alice/files/Docs']['id']];
        $this->scope = new \OCA\Mcp\Access\ResourceScope\FilesReferenceScope(\OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes::over(
            $this, $this->guard, $this->caller->filesystemRoot(), static fn (): array => $selected));
        self::assertSame(self::SHARE, $this->readAttachmentId($shares, self::SHARE), 'inside the scope it reads as before');
    }

    /** @return array<string, array{bool}> whether exclusion tags are configured, the case that used to mask C1 */
    public static function withTags(): array {
        return ['without tags' => [false], 'with a tag configured' => [true]];
    }

    /** The Talk module over the real attach, quote, preview and read paths, the conversation resolved as writable. */
    private function module(IShareManager $shares): \OCA\Mcp\Modules\Talk\TalkModule {
        $resolver = $this->createMock(ConversationResolver::class);
        $resolver->method('resolveForWriting')->willReturn($this->conversation());
        $resolver->method('resolveForReading')->willReturn($this->conversation());
        $files = new UserFileResolver($this->caller->filesystemRoot(), $this->guard, $this->filter($shares));
        $sharer = new FileSharer($shares, $files, $this->createMock(\OCA\Mcp\Modules\Talk\Messaging\MessageSender::class));
        return \OCA\Mcp\Tests\Support\Talk\TalkModuleFixtures::create([
            ConversationResolver::class => $resolver,
            ConversationWriter::class => $this->writer($shares),
            AttachmentAccess::class => $this->access($shares),
            FileSharer::class => $sharer,
            ConversationReader::class => new ConversationReader(static fn () => throw new \LogicException('Unused manager'),
                static fn () => throw new \LogicException('Unused participants'), fn () => $this->chat(), $resolver,
                new ActorNames($this->createMock(IUserManager::class)), $this->filter($shares)),
        ], $this->createMock(\Psr\Log\LoggerInterface::class));
    }

    /** @return string what the client gets: the endpoint's 503, or the tool's own answer */
    private static function answer(callable $run): string {
        try {
            return json_encode($run());
        } catch (\OCA\Mcp\Access\Policy\PolicyUnavailable) {
            return '503';
        } catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
            return 'refused: ' . $e->getMessage();
        }
    }

    /**
     * C1 of the second B5 review (D6, contract B.5): with the Files scope unreadable, attaching or quoting a file that
     * exists outside the scope and one that does not get the same 503, in the plan and in the confirmed call, with or
     * without exclusion tags; the scope is read before any file or share is looked up. talk_read_messages too.
     */
    #[DataProvider('withTags')]
    public function testC1AnUnreadableScopeIsTheSame503ForExistingAndMissingReferences(bool $tags): void {
        $this->given(['/alice/files/Archive/private.txt']);
        $this->caller->nodes['/alice/files/Archive/private.txt']['id'] = self::FILE_ID;
        if (!$tags) {
            $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
            $this->guard->clearCache();
        }
        $readable = true;
        $selected = [(string)$this->caller->addFolder('/alice/files/Projects')];
        $this->scope = new \OCA\Mcp\Access\ResourceScope\FilesReferenceScope(\OCA\Mcp\Tests\Support\Access\ResourceScope\FilesScopes::over(
            $this, $this->guard, $this->caller->filesystemRoot(), static function () use (&$readable, $selected): array|false {
                return $readable ? $selected : false;
            }));
        $module = $this->module($this->shares());
        $cases = [
            'attach plan' => [fn () => $module->preview('talk_attach_file', ['conversation_token' => 'room1', 'path' => 'Archive/private.txt'], 'alice'),
                fn () => $module->preview('talk_attach_file', ['conversation_token' => 'room1', 'path' => 'Archive/missing.txt'], 'alice')],
            'attach' => [fn () => $module->call('talk_attach_file', ['conversation_token' => 'room1', 'path' => 'Archive/private.txt'], 'alice'),
                fn () => $module->call('talk_attach_file', ['conversation_token' => 'room1', 'path' => 'Archive/missing.txt'], 'alice')],
            'quote plan' => [fn () => $module->preview('talk_quote_file', ['conversation_token' => 'room1', 'attachment_id' => self::SHARE], 'alice'),
                fn () => $module->preview('talk_quote_file', ['conversation_token' => 'room1', 'attachment_id' => self::MISSING_SHARE], 'alice')],
            'quote' => [fn () => $module->call('talk_quote_file', ['conversation_token' => 'room1', 'attachment_id' => self::SHARE], 'alice'),
                fn () => $module->call('talk_quote_file', ['conversation_token' => 'room1', 'attachment_id' => self::MISSING_SHARE], 'alice')],
        ];
        // With the scope readable both are the ordinary refusal, identical: the file is outside the scope.
        foreach ($cases as $label => [$existing, $missing]) {
            $this->assertSame(self::answer($missing), self::answer($existing), "readable, $label");
            $this->assertNotSame('503', self::answer($existing), "readable, $label");
        }
        $readable = false;
        foreach ($cases as $label => [$existing, $missing]) {
            $this->assertSame('503', self::answer($existing), "unreadable, existing, $label");
            $this->assertSame('503', self::answer($missing), "unreadable, missing, $label");
        }
        $this->assertSame('503', self::answer(fn () => $module->call('talk_read_messages', ['conversation_token' => 'room1', 'limit' => 10], 'alice')));
        $this->assertSame([], $this->published, 'nothing is published');
    }
}

/** Talk room double. */
final class TalkParityRoomStub {
    public function __construct(private string $token) {}
    public function getToken(): string {
        return $this->token;
    }
    public function getDisplayName(string $userId): string {
        return 'Team';
    }
}

/** Talk participant double. */
final class TalkParityParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}

/** Chat surface the reader and the writer use. */
interface TalkParityChat {
    /** @return array<IComment> */
    public function getHistory(object $room, int $offset, int $limit, bool $includeLastKnown, int $threadId = 0): array;

    public function addSystemMessage(object $room, object $participant, string $actorType, string $actorId, string $message, DateTime $when, bool $systemMessage = false): object;
}
