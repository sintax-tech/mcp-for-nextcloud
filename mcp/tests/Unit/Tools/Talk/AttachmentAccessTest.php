<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\Messages;
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
        $this->access = new AttachmentAccess($this->shareManager);
    }

    public function testARoomShareOfThisConversationIsAccepted(): void {
        $this->givenShare(IShare::TYPE_ROOM, 'abcd');

        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);

        $this->addToAssertionCount(1);
    }

    public function testTheShareIsOnlyReadAndNeverWritten(): void {
        $this->shareManager->expects($this->once())
            ->method('getShareById')
            ->with('77', 'alice')
            ->willReturn($this->givenShare(IShare::TYPE_ROOM, 'abcd'));
        // Reading the share is all this may do: creating one here would publish the attachment a second time.
        $this->shareManager->expects($this->never())->method('createShare');
        $this->shareManager->expects($this->never())->method('newShare');

        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }

    public function testAShareOfAnotherConversationIsRefused(): void {
        $this->givenShare(IShare::TYPE_ROOM, 'zzzz');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::ATTACHMENT_NOT_FOUND);
        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }

    public function testAShareThatIsNotOfTheConversationKindIsRefused(): void {
        $this->givenShare(IShare::TYPE_LINK, 'abcd');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::ATTACHMENT_NOT_FOUND);
        $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
    }


    public function testSomethingTheUserCannotSeeIsRefusedWithoutLeakingTheCause(): void {
        $this->shareManager->method('getShareById')
            ->willThrowException(new RuntimeException('Share 77 is not visible to alice at 10.0.0.9'));

        try {
            $this->access->requireRoomShareOf($this->givenConversation(), 'alice', 77);
            $this->fail('An attachment the user cannot see must be refused.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::ATTACHMENT_NOT_FOUND, $e->getMessage());
            $this->assertStringNotContainsString('not visible', $e->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $e->getMessage());
            // The cause is kept for the log only.
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    private function givenShare(int $shareType, string $sharedWith): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn($shareType);
        $share->method('getSharedWith')->willReturn($sharedWith);
        $this->shareManager->method('getShareById')->willReturn($share);

        return $share;
    }

    private function givenConversation(): Conversation {
        return new Conversation(new AccessRoomStub('abcd'), new AccessParticipantStub());
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