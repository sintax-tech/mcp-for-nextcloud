<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\UserFileResolver;
use OCP\Constants;
use OCP\Files\File;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class FileSharerTest extends TestCase {
    private IShareManager&MockObject $shareManager;
    private ShareableFileResolverStub&MockObject $fileResolver;
    private ConversationWriter&MockObject $writer;
    private FileSharer $sharer;
    private File&MockObject $file;

    protected function setUp(): void {
        parent::setUp();
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->fileResolver = $this->createMock(ShareableFileResolverStub::class);
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = new FileSharer($this->shareManager, $this->fileResolver, $this->writer);
        $this->file = $this->createMock(File::class);
        $this->file->method('getPath')->willReturn('/alice/files/relatorio.pdf');
        $this->file->method('getName')->willReturn('relatorio.pdf');
        $this->file->method('getSize')->willReturn(2048);
    }

    /** No share of this file exists yet, neither by the user nor by anybody else. */
    private function givenNoExistingShare(): void {
        $this->shareManager->method('getSharesBy')->willReturn([]);
        $this->shareManager->method('getSharedWith')->willReturn([]);
    }

    public function testShareCreatesTheRoomShareAndReturnsTheAttachmentData(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $share = $this->givenCreatedShare(77);

        $result = $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');

        $this->assertSame([
            'conversation_token' => 'abcd',
            'attachmentId' => 77,
            'file' => [
                'path' => '/alice/files/relatorio.pdf',
                'name' => 'relatorio.pdf',
                'size' => 2048,
            ],
            'caption' => null,
            'captionSent' => null,
        ], $result);
        $this->assertSame($this->file, $share->node);
        $this->assertSame(IShare::TYPE_ROOM, $share->type);
        $this->assertSame('abcd', $share->with);
        $this->assertSame('alice', $share->by);
        $this->assertSame(Constants::PERMISSION_READ, $share->permissions);
    }

    public function testShareNeverPublishesTheAttachmentCardItself(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->givenCreatedShare(77);
        // quoteAttachment is the only route to the file_shared envelope, and it would post a second card.
        $this->writer->expects($this->never())->method('quoteAttachment');
        $this->writer->expects($this->never())->method('sendText');

        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    public function testCaptionIsSentAsASecondMessageAfterTheCard(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->givenCreatedShare(77);
        $this->writer->expects($this->once())
            ->method('sendText')
            ->with($this->anything(), 'alice', 'olha o relatório')
            ->willReturn(88);
        $this->writer->expects($this->never())->method('quoteAttachment');

        $result = $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf', 'olha o relatório');

        $this->assertSame(88, $result['messageId']);
        $this->assertTrue($result['captionSent']);
    }

    public function testACaptionThatFailsAfterTheCardIsPublishedIsReportedAsAPartialSuccess(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->givenCreatedShare(77);
        $this->writer->method('sendText')
            ->willThrowException(new ConversationAccessException(Messages::MESSAGE_NOT_SENT));

        // The share is already in the room: an error result would hide an attachment that really was published,
        // and the caller would retry an attach that can only end in "already shared" with the caption lost.
        $result = $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf', 'olha o relatório');

        $this->assertSame(77, $result['attachmentId']);
        $this->assertFalse($result['captionSent']);
        $this->assertSame('olha o relatório', $result['caption']);
        $this->assertArrayNotHasKey('messageId', $result);
        $this->assertSame(Messages::CAPTION_NOT_SENT, $result['message']);
        $this->assertStringContainsString('talk_reply', $result['message']);
    }

    public function testAPartialSuccessNeverDeletesTheShareItJustCreated(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->givenCreatedShare(77);
        $this->writer->method('sendText')->willThrowException(new RuntimeException('room went read-only'));
        // Compensation would be worse than the failure: other participants may already have seen the card, and
        // a delete of a room share can fail on its own. So nothing is removed here.
        $this->shareManager->expects($this->never())->method('deleteShare');

        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf', 'legenda');
    }

    public function testFileAlreadySharedByAnotherParticipantIsRefusedBeforeCreatingAnything(): void {
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->shareManager->method('getSharesBy')->willReturn([]);
        $this->shareManager->method('getSharedWith')->willReturn([$this->givenExistingShare('abcd')]);
        $this->shareManager->expects($this->never())->method('newShare');
        $this->shareManager->expects($this->never())->method('createShare');
        $this->writer->expects($this->never())->method('sendText');

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::FILE_ALREADY_SHARED);
        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    public function testFileAlreadySharedByTheUserIsRefusedBeforeCreatingAnything(): void {
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->shareManager->method('getSharesBy')->willReturn([$this->givenExistingShare('abcd')]);
        $this->shareManager->expects($this->never())->method('createShare');

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::FILE_ALREADY_SHARED);
        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    public function testFileSharedInAnotherConversationIsNotRefused(): void {
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $other = $this->givenExistingShare('zzzz');
        $this->shareManager->method('getSharesBy')->willReturn([$other]);
        $this->shareManager->method('getSharedWith')->willReturn([$other]);
        $this->givenCreatedShare(77);

        $result = $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');

        $this->assertSame(77, $result['attachmentId']);
    }

    public function testDuplicateCheckAsksForEveryShareOfTheFile(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->givenCreatedShare(77);
        $this->shareManager->expects($this->once())
            ->method('getSharesBy')
            ->with('alice', IShare::TYPE_ROOM, $this->file, false, -1);
        $this->shareManager->expects($this->once())
            ->method('getSharedWith')
            ->with('alice', IShare::TYPE_ROOM, $this->file, -1);

        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    public function testRefusedShareBecomesAGenericFailure(): void {
        $this->givenNoExistingShare();
        $this->fileResolver->method('resolveShareableFile')->willReturn($this->file);
        $this->shareManager->method('newShare')->willThrowException(new RuntimeException('Room is read only'));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::FILE_NOT_SHARED);
        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    public function testUnreadableFileStopsBeforeAnyShareLookup(): void {
        $this->fileResolver->method('resolveShareableFile')
            ->willThrowException(new FileAccessException(Messages::FILE_NOT_FOUND));
        $this->shareManager->expects($this->never())->method('newShare');
        $this->shareManager->expects($this->never())->method('getSharesBy');

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::FILE_NOT_FOUND);
        $this->sharer->attach($this->givenConversation('abcd'), 'alice', 'relatorio.pdf');
    }

    private function givenConversation(string $token): Conversation {
        return new Conversation(new SharerRoomStub($token), new SharerParticipantStub());
    }

    private function givenExistingShare(string $token): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getSharedWith')->willReturn($token);

        return $share;
    }

    private function givenCreatedShare(int $shareId): stdClass {
        $configured = new stdClass();
        $share = $this->createMock(IShare::class);
        $share->method('setNode')->willReturnCallback($this->record($configured, 'node', $share));
        $share->method('setShareType')->willReturnCallback($this->record($configured, 'type', $share));
        $share->method('setSharedWith')->willReturnCallback($this->record($configured, 'with', $share));
        $share->method('setSharedBy')->willReturnCallback($this->record($configured, 'by', $share));
        $share->method('setPermissions')->willReturnCallback($this->record($configured, 'permissions', $share));
        $share->method('getId')->willReturn((string)$shareId);
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')->willReturn($share);

        return $configured;
    }

    /**
     * Keeps what the module asked the share to be, so the test can check the share was built as the contract says.
     *
     * @param stdClass $configured Collector filled by the returned callback
     * @param string $property Property to fill
     * @param IShare $share The share being configured, returned so the call can be chained
     * @return callable The value setter
     */
    private function record(stdClass $configured, string $property, IShare $share): callable {
        return function ($value) use ($configured, $property, $share) {
            $configured->{$property} = $value;

            return $share;
        };
    }
}

/** Replaces the filesystem lookup so the share logic can be tested without storage. */
class ShareableFileResolverStub extends UserFileResolver {
    public function __construct() {
    }

    public function resolveShareableFile(string $userId, string $path): File {
        throw new RuntimeException('The test must configure resolveShareableFile().');
    }
}

final class SharerRoomStub {
    public function __construct(private string $token) {}

    public function getToken(): string {
        return $this->token;
    }
}

final class SharerParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}
