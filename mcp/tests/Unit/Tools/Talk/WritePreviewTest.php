<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\WritePreview;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The plan is what a user approves instead of a message, so it has to carry exactly what the confirmed call would
 * publish: the same text, the same conversation, the quoted message and the file. It also has to refuse whatever
 * the send would refuse, otherwise showing the plan means nothing and the confirmed call fails afterwards.
 *
 * The plan is stateless. No approval id is handed out, no state is kept and the confirmed call is only the same
 * call with confirm: true, so there is nothing here that a second call could spend, replay or carry to another
 * account.
 */
class WritePreviewTest extends TestCase {
    private ConversationWriter&MockObject $writer;
    private FileSharer&MockObject $sharer;
    private AttachmentAccess&MockObject $attachmentAccess;
    private IUserManager&MockObject $userManager;
    private WritePreview $previews;

    protected function setUp(): void {
        parent::setUp();
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->attachmentAccess = $this->createMock(AttachmentAccess::class);
        $this->userManager = $this->createMock(IUserManager::class);
        // The real ActorNames over the mocked accounts: a plan has to name the author the way the read does.
        $this->previews = new WritePreview(
            $this->writer,
            $this->sharer,
            $this->attachmentAccess,
            new ActorNames($this->userManager),
        );
    }

    public function testAReplyDraftCarriesTheExactTextAndTheConversation(): void {
        $draft = $this->previews->reply($this->givenConversation(), 'alice', "  bom dia \n", null);

        $this->assertTrue($draft['requiresConfirmation']);
        $this->assertSame('talk_reply', $draft['action']);
        $this->assertSame(['token' => 'abcd', 'displayName' => 'Comercial'], $draft['conversation']);
        // The trimmed text is what the confirmed call will store, so the draft cannot differ from the send.
        $this->assertSame('bom dia', $draft['draft']['message']);
        $this->assertArrayNotHasKey('replyTo', $draft['draft']);
    }

    public function testADirectMessageDraftNamesThePersonAndCarriesNoConversation(): void {
        $draft = $this->previews->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', "  oi \n");

        $this->assertTrue($draft['requiresConfirmation']);
        $this->assertSame('talk_message_user', $draft['action']);
        // The room may not exist yet: naming a token the draft has not created would be inventing one.
        $this->assertArrayNotHasKey('conversation', $draft);
        $this->assertSame(['id' => 'bob', 'displayName' => 'Bob Souza'], $draft['target']);
        $this->assertSame('oi', $draft['draft']['message']);
    }

    public function testAGroupDraftNamesTheGroupAndTheGuestsAndCarriesNoConversation(): void {
        $draft = $this->previews->group(
            'alice',
            '  Projeto X  ',
            [new DirectContact('bob', 'Bob Souza'), new DirectContact('carol', 'Carol Lima')],
        );

        $this->assertTrue($draft['requiresConfirmation']);
        $this->assertSame('talk_create_group', $draft['action']);
        // The room does not exist yet: a preview that created one would leave a group nobody approved behind.
        $this->assertArrayNotHasKey('conversation', $draft);
        $this->assertSame(
            [
                'name' => 'Projeto X',
                'participants' => [
                    ['id' => 'bob', 'displayName' => 'Bob Souza'],
                    ['id' => 'carol', 'displayName' => 'Carol Lima'],
                ],
            ],
            $draft['draft'],
        );
        // The instruction repeats what is about to happen: the user approves a name and a guest list.
        $this->assertStringContainsString('Projeto X', $draft['message']);
        $this->assertStringContainsString('Bob Souza, Carol Lima', $draft['message']);
        $this->assertStringContainsString('confirm: true', $draft['message']);
    }

    public function testAGroupDraftWithoutGuestsDoesNotPromiseAnyInvitation(): void {
        $draft = $this->previews->group('alice', 'Projeto X', []);

        $this->assertSame([], $draft['draft']['participants']);
        $this->assertStringNotContainsString('guests', $draft['message']);
    }

    public function testABlankGroupNameIsAClientMistakeBeforeAnyPreview(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidGroupName());

        $this->previews->group('alice', '   ', []);
    }

    public function testABatchDraftShowsEveryItemWithTheExactTextAndTheQuotedAnswer(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $draft = $this->previews->batch($this->givenConversation(), 'alice', [
            ['message' => "  primeiro \n"],
            ['message' => 'segundo', 'replyTo' => 42],
        ]);

        $this->assertSame('talk_send_batch', $draft['action']);
        $this->assertSame(
            [
                ['message' => 'primeiro'],
                [
                    'message' => 'segundo',
                    'replyTo' => ['id' => 42, 'author' => 'Bob Souza', 'excerpt' => 'concordo com o envio'],
                ],
            ],
            $draft['draft']['messages'],
        );
    }

    public function testAnEmptyOrOversizedBatchIsAClientMistake(): void {
        $conversation = $this->givenConversation();

        try {
            $this->previews->batch($conversation, 'alice', []);
            $this->fail('an empty batch was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::emptyBatch(), $e->getMessage());
        }

        $tooMany = array_fill(0, WritePreview::MAX_BATCH + 1, ['message' => 'oi']);
        try {
            $this->previews->batch($conversation, 'alice', $tooMany);
            $this->fail('a batch over the limit was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::tooManyMessages(WritePreview::MAX_BATCH), $e->getMessage());
        }
    }

    public function testABatchWithABlankItemIsRefusedBeforeAnyCitationIsRead(): void {
        $conversation = $this->givenConversation();
        $this->writer->expects($this->never())->method('quoteTarget');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::emptyMessage());
        $this->previews->batch($conversation, 'alice', [
            ['message' => 'primeiro', 'replyTo' => 7],
            ['message' => '   '],
        ]);
    }

    public function testTheDirectMessageDraftNamesThePersonInTheInstruction(): void {
        $draft = $this->previews->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi');

        $this->assertSame(Messages::confirmationInstructionTarget('Bob Souza'), $draft['message']);
        $this->assertStringContainsString('confirm: true', $draft['message']);
    }

    public function testTheDraftTellsTheAgentToShowItAndToWaitForTheApproval(): void {
        $draft = $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null);

        $this->assertSame(
            Messages::confirmationInstruction('Comercial'),
            $draft['message'],
        );
        $this->assertStringContainsString("'Comercial'", $draft['message']);
        $this->assertStringContainsString('confirm: true', $draft['message']);
    }

    public function testAQuotedReplyShowsWhoIsBeingAnsweredAndWhat(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $draft = $this->previews->reply($this->givenConversation(), 'alice', 'concordo', 42);

        $this->assertSame(
            ['id' => 42, 'author' => 'Bob Souza', 'excerpt' => 'concordo com o envio'],
            $draft['draft']['replyTo'],
        );
    }

    public function testTheExcerptOfALongMessageIsShortened(): void {
        $this->givenQuotedComment('42', 'bob', str_repeat('a', 400));

        $draft = $this->previews->reply($this->givenConversation(), 'alice', 'concordo', 42);

        $this->assertSame(WritePreview::EXCERPT_MAX_LENGTH, mb_strlen($draft['draft']['replyTo']['excerpt']));
    }

    public function testAQuotedCardIsNamedByItsTypeBecauseThereIsNoTextToQuote(): void {
        $envelope = json_encode(['message' => 'file_shared', 'parameters' => ['share' => '77']]);
        $this->givenQuotedComment('42', 'bob', (string)$envelope);

        $draft = $this->previews->reply($this->givenConversation(), 'alice', 'olha', 42);

        $this->assertSame('[file_shared]', $draft['draft']['replyTo']['excerpt']);
    }

    public function testAnAuthorThatIsNotAnAccountKeepsItsRawId(): void {
        $this->userManager->method('get')->with('guest-9')->willReturn(null);
        $this->givenQuotedComment('42', 'guest-9', 'oi');

        $draft = $this->previews->reply($this->givenConversation(), 'alice', 'olá', 42);

        $this->assertSame('guest-9', $draft['draft']['replyTo']['author']);
    }

    public function testAMessageTheSendWouldRefuseIsRefusedByTheDraftToo(): void {
        $this->writer->expects($this->never())->method('quoteTarget');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::emptyMessage());
        $this->previews->reply($this->givenConversation(), 'alice', "   \n ", null);
    }

    public function testAMessageAboveTheLimitIsRefusedByTheDraftToo(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::messageTooLong());
        $this->previews->reply(
            $this->givenConversation(),
            'alice',
            str_repeat('a', ConversationWriter::MAX_MESSAGE_LENGTH + 1),
            null,
        );
    }

    public function testAQuotedIdThatDoesNotExistIsRefusedBeforeAnyDraft(): void {
        $this->writer->method('quoteTarget')
            ->willThrowException(new ConversationAccessException(Messages::replyTargetNotFound()));

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::replyTargetNotFound());
        $this->previews->reply($this->givenConversation(), 'alice', 'concordo', 99);
    }

    public function testAnAttachDraftCarriesTheFileAndTheCaption(): void {
        $conversation = $this->givenConversation();
        $this->sharer->expects($this->once())
            ->method('previewFile')
            ->with($conversation, 'alice', 'relatorio.pdf')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048]);

        $draft = $this->previews->attach($conversation, 'alice', 'relatorio.pdf', ' olha o relatório ');

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

        $draft = $this->previews->attach($this->givenConversation(), 'alice', 'relatorio.pdf', null);

        // The key stays, so the agent reads one shape and finds no text to show.
        $this->assertArrayHasKey('message', $draft['draft']);
        $this->assertNull($draft['draft']['message']);
    }

    public function testAFileThatCannotBeSharedIsRefusedBeforeAnyDraft(): void {
        $this->sharer->method('previewFile')->willThrowException(new FileAccessException(Messages::fileNotFound()));

        $this->expectException(FileAccessException::class);
        $this->expectExceptionMessage(Messages::fileNotFound());
        $this->previews->attach($this->givenConversation(), 'alice', 'sumiu.pdf', null);
    }

    public function testAQuoteDraftCarriesTheCaptionAndAsksTheShareRule(): void {
        $conversation = $this->givenConversation();
        $share = $this->givenShare();
        $this->attachmentAccess->expects($this->once())
            ->method('requireRoomShareOf')
            ->with($conversation, 'alice', 77)
            ->willReturn($share);
        $this->attachmentAccess->expects($this->once())
            ->method('describe')
            ->with($share)
            ->willReturn(['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1024, 'mimeType' => 'image/png']);

        $draft = $this->previews->quote($conversation, 'alice', 77, ' a figura ');

        $this->assertSame('talk_quote_file', $draft['action']);
        $this->assertSame('a figura', $draft['draft']['message']);
        // The user approves a file, not an id: 77 alone would tell nobody what is about to be cited.
        $this->assertSame(
            ['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1024, 'mimeType' => 'image/png'],
            $draft['draft']['file'],
        );
    }

    public function testAQuoteDraftWithoutACaptionStillNamesTheFile(): void {
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')
            ->willReturn(['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1024, 'mimeType' => 'image/png']);

        $draft = $this->previews->quote($this->givenConversation(), 'alice', 77, null);

        $this->assertNull($draft['draft']['message']);
        $this->assertSame(77, $draft['draft']['file']['attachmentId']);
        $this->assertSame('figura.png', $draft['draft']['file']['name']);
    }

    public function testAShareIsReadOnceForTheQuoteAndNeverCreated(): void {
        $this->attachmentAccess->expects($this->once())
            ->method('requireRoomShareOf')
            ->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')->willReturn([
            'attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png',
        ]);

        $this->previews->quote($this->givenConversation(), 'alice', 77, null);

        // Naming the file must not touch the share: the card itself is the only thing that publishes one.
        $this->addToAssertionCount(1);
    }

    public function testAnAttachmentOfAnotherConversationIsRefusedBeforeAnyDraft(): void {
        $this->attachmentAccess->method('requireRoomShareOf')
            ->willThrowException(new ConversationAccessException(Messages::attachmentNotFound()));

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::attachmentNotFound());
        $this->previews->quote($this->givenConversation(), 'alice', 99, null);
    }

    public function testABlankCaptionIsRefusedByTheQuoteRule(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::emptyMessage());
        $this->previews->quote($this->givenConversation(), 'alice', 77, '   ');
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

        $this->previews->reply($conversation, 'alice', 'oi', 42);
        $this->previews->attach($conversation, 'alice', 'relatorio.pdf', 'oi');
        $this->previews->quote($conversation, 'alice', 77, null);
    }

    /**
     * Nothing in a plan may be spent, replayed or carried to another account: the plan carries no id and no
     * server-side record, so a confirmed call is only the same call with confirm: true.
     */
    public function testNoPlanCarriesAnIdAndEveryPlanAsksForConfirmation(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')->willReturn([
            'attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png',
        ]);
        $conversation = $this->givenConversation();

        foreach (
            [
                $this->previews->reply($conversation, 'alice', 'oi', null),
                $this->previews->attach($conversation, 'alice', 'relatorio.pdf', null),
                $this->previews->quote($conversation, 'alice', 77, null),
                $this->previews->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi'),
                $this->previews->group('alice', 'Projeto X', [new DirectContact('bob', 'Bob Souza')]),
            ] as $plan
        ) {
            $this->assertArrayNotHasKey('approvalId', $plan);
            $this->assertArrayNotHasKey('approval_id', $plan);
            $this->assertTrue($plan['requiresConfirmation']);
            $this->assertStringContainsString('confirm: true', $plan['message']);
        }
    }

    /** The same plan comes back for the same arguments, because nothing about it was stored. */
    public function testThePlanOfTheSameCallIsTheSamePlan(): void {
        $first = $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null);
        $second = $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null);

        $this->assertSame($first, $second);
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

    private function givenConversation(string $token = 'abcd'): Conversation {
        return new Conversation(new PreviewRoomStub($token, 'Comercial'), new PreviewParticipantStub());
    }

    private function givenShare(): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('77');

        return $share;
    }
}

final class PreviewRoomStub {
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

final class PreviewParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}