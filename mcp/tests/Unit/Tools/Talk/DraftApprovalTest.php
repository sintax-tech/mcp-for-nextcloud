<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DraftApproval;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The draft is what a user approves instead of a message, so it has to carry exactly what the confirmed call
 * would publish: the same text, the same conversation, the quoted message and the file. It also has to refuse
 * whatever the send would refuse, otherwise approval means nothing and the approved call fails afterwards.
 */
class DraftApprovalTest extends TestCase {
    private ConversationWriter&MockObject $writer;
    private FileSharer&MockObject $sharer;
    private AttachmentAccess&MockObject $attachmentAccess;
    private IUserManager&MockObject $userManager;
    private DraftApproval $drafts;

    protected function setUp(): void {
        parent::setUp();
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->attachmentAccess = $this->createMock(AttachmentAccess::class);
        $this->userManager = $this->createMock(IUserManager::class);
        // The real ActorNames over the mocked accounts: a draft has to name the author the way the read does.
        $this->drafts = new DraftApproval(
            $this->writer,
            $this->sharer,
            $this->attachmentAccess,
            new ActorNames($this->userManager),
        );
    }

    public function testAReplyDraftCarriesTheExactTextAndTheConversation(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', "  bom dia \n", null);

        $this->assertTrue($draft['requiresConfirmation']);
        $this->assertSame('talk_reply', $draft['action']);
        $this->assertSame(['token' => 'abcd', 'displayName' => 'Comercial'], $draft['conversation']);
        // The trimmed text is what the confirmed call will store, so the draft cannot differ from the send.
        $this->assertSame('bom dia', $draft['draft']['message']);
        $this->assertArrayNotHasKey('replyTo', $draft['draft']);
    }

    public function testTheDraftTellsTheAgentToShowItAndToWaitForTheApproval(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'bom dia', null);

        $this->assertSame(
            sprintf(Messages::CONFIRMATION_INSTRUCTION, 'Comercial'),
            $draft['message'],
        );
        $this->assertStringContainsString("'Comercial'", $draft['message']);
        $this->assertStringContainsString('confirm: true', $draft['message']);
    }

    public function testAQuotedReplyShowsWhoIsBeingAnsweredAndWhat(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'concordo', 42);

        $this->assertSame(
            ['id' => 42, 'author' => 'Bob Souza', 'excerpt' => 'concordo com o envio'],
            $draft['draft']['replyTo'],
        );
    }

    public function testTheExcerptOfALongMessageIsShortened(): void {
        $this->givenQuotedComment('42', 'bob', str_repeat('a', 400));

        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'concordo', 42);

        $this->assertSame(DraftApproval::EXCERPT_MAX_LENGTH, mb_strlen($draft['draft']['replyTo']['excerpt']));
    }

    public function testAQuotedCardIsNamedByItsTypeBecauseThereIsNoTextToQuote(): void {
        $envelope = json_encode(['message' => 'file_shared', 'parameters' => ['share' => '77']]);
        $this->givenQuotedComment('42', 'bob', (string)$envelope);

        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'olha', 42);

        $this->assertSame('[file_shared]', $draft['draft']['replyTo']['excerpt']);
    }

    public function testAnAuthorThatIsNotAnAccountKeepsItsRawId(): void {
        $this->userManager->method('get')->with('guest-9')->willReturn(null);
        $this->givenQuotedComment('42', 'guest-9', 'oi');

        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'olá', 42);

        $this->assertSame('guest-9', $draft['draft']['replyTo']['author']);
    }

    public function testAMessageTheSendWouldRefuseIsRefusedByTheDraftToo(): void {
        $this->writer->expects($this->never())->method('quoteTarget');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::EMPTY_MESSAGE);
        $this->drafts->reply($this->givenConversation(), 'alice', "   \n ", null);
    }

    public function testAMessageAboveTheLimitIsRefusedByTheDraftToo(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::MESSAGE_TOO_LONG);
        $this->drafts->reply(
            $this->givenConversation(),
            'alice',
            str_repeat('a', ConversationWriter::MAX_MESSAGE_LENGTH + 1),
            null,
        );
    }

    public function testAQuotedIdThatDoesNotExistIsRefusedBeforeAnyDraft(): void {
        $this->writer->method('quoteTarget')
            ->willThrowException(new ConversationAccessException(Messages::REPLY_TARGET_NOT_FOUND));

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::REPLY_TARGET_NOT_FOUND);
        $this->drafts->reply($this->givenConversation(), 'alice', 'concordo', 99);
    }

    public function testAnAttachDraftCarriesTheFileAndTheCaption(): void {
        $conversation = $this->givenConversation();
        $this->sharer->expects($this->once())
            ->method('previewFile')
            ->with($conversation, 'alice', 'relatorio.pdf')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048]);

        $draft = $this->drafts->attach($conversation, 'alice', 'relatorio.pdf', ' olha o relatório ');

        $this->assertSame('talk_attach_file', $draft['action']);
        $this->assertSame('olha o relatório', $draft['draft']['message']);
        $this->assertSame(
            ['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048],
            $draft['draft']['file'],
        );
    }

    public function testAnAttachDraftWithoutACaptionReportsNoMessageAtAll(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048]);

        $draft = $this->drafts->attach($this->givenConversation(), 'alice', 'relatorio.pdf', null);

        // The key stays, so the agent reads one shape and finds no text to show.
        $this->assertArrayHasKey('message', $draft['draft']);
        $this->assertNull($draft['draft']['message']);
    }

    public function testAFileThatCannotBeSharedIsRefusedBeforeAnyDraft(): void {
        $this->sharer->method('previewFile')->willThrowException(new FileAccessException(Messages::FILE_NOT_FOUND));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::FILE_NOT_FOUND);
        $this->drafts->attach($this->givenConversation(), 'alice', 'sumiu.pdf', null);
    }

    public function testAQuoteDraftCarriesTheCaptionAndAsksTheShareRule(): void {
        $conversation = $this->givenConversation();
        $this->attachmentAccess->expects($this->once())
            ->method('requireRoomShareOf')
            ->with($conversation, 'alice', 77);

        $draft = $this->drafts->quote($conversation, 'alice', 77, ' a figura ');

        $this->assertSame('talk_quote_file', $draft['action']);
        $this->assertSame('a figura', $draft['draft']['message']);
    }

    public function testAQuoteDraftWithoutACaptionCarriesNoMetaDataAtAll(): void {
        $draft = $this->drafts->quote($this->givenConversation(), 'alice', 77, null);

        $this->assertSame(['message' => null], $draft['draft']);
    }

    public function testAnAttachmentOfAnotherConversationIsRefusedBeforeAnyDraft(): void {
        $this->attachmentAccess->method('requireRoomShareOf')
            ->willThrowException(new ConversationAccessException(Messages::ATTACHMENT_NOT_FOUND));

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::ATTACHMENT_NOT_FOUND);
        $this->drafts->quote($this->givenConversation(), 'alice', 99, null);
    }

    public function testABlankCaptionIsRefusedByTheQuoteRule(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::EMPTY_MESSAGE);
        $this->drafts->quote($this->givenConversation(), 'alice', 77, '   ');
    }

    public function testThePreviewNeverPublishesAnything(): void {
        $conversation = $this->givenConversation();
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $this->givenQuotedComment('42', 'bob', 'oi');

        // The only writes Talk knows are these three: a draft must reach none of them.
        $this->writer->expects($this->never())->method('reply');
        $this->writer->expects($this->never())->method('quoteAttachment');
        $this->sharer->expects($this->never())->method('attach');

        $this->drafts->reply($conversation, 'alice', 'oi', 42);
        $this->drafts->attach($conversation, 'alice', 'relatorio.pdf', 'oi');
        $this->drafts->quote($conversation, 'alice', 77, null);
    }

    private function givenQuotedComment(string $id, string $actorId, string $message): IComment&MockObject {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn($id);
        $comment->method('getActorId')->willReturn($actorId);
        $comment->method('getMessage')->willReturn($message);
        $this->writer->method('quoteTarget')->willReturn($comment);

        return $comment;
    }

    private function givenUser(string $displayName): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);

        return $user;
    }

    private function givenConversation(): Conversation {
        return new Conversation(new DraftRoomStub('abcd', 'Comercial'), new DraftParticipantStub());
    }
}

final class DraftRoomStub {
    public function __construct(
        private string $token,
        private string $displayName,
    ) {}

    public function getToken(): string {
        return $this->token;
    }

    public function getDisplayName(string $userId): string {
        return $this->displayName;
    }
}

final class DraftParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}