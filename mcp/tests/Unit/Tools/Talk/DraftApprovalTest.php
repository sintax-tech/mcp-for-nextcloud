<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\ApprovalException;
use OCA\Mcp\Tools\Talk\ApprovalRegistry;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\DraftApproval;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCP\Comments\IComment;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The draft is what a user approves instead of a message, so it has to carry exactly what the confirmed call
 * would publish: the same text, the same conversation, the quoted message and the file. It also has to refuse
 * whatever the send would refuse, otherwise approval means nothing and the approved call fails afterwards.
 *
 * The approvalId it hands out is the second half of the promise: the approved call only publishes when it carries
 * that id for the same account, conversation, action and payload, so a draft cannot be shown and then published
 * with something else in place.
 */
class DraftApprovalTest extends TestCase {
    private ConversationWriter&MockObject $writer;
    private FileSharer&MockObject $sharer;
    private AttachmentAccess&MockObject $attachmentAccess;
    private IUserManager&MockObject $userManager;
    private ApprovalRegistry $approvals;
    private DraftApproval $drafts;

    protected function setUp(): void {
        parent::setUp();
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->attachmentAccess = $this->createMock(AttachmentAccess::class);
        $this->userManager = $this->createMock(IUserManager::class);
        // The real registry over an in-memory config: the lock the approved call depends on is under test too.
        $this->approvals = new ApprovalRegistry($this->givenAppConfig(), $this->givenRandom());
        // The real ActorNames over the mocked accounts: a draft has to name the author the way the read does.
        $this->drafts = new DraftApproval(
            $this->writer,
            $this->sharer,
            $this->attachmentAccess,
            new ActorNames($this->userManager),
            $this->approvals,
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

    public function testADirectMessageDraftNamesThePersonAndCarriesNoConversation(): void {
        $draft = $this->drafts->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', "  oi \n");

        $this->assertTrue($draft['requiresConfirmation']);
        $this->assertSame('talk_message_user', $draft['action']);
        // The room may not exist yet: naming a token the draft has not created would be inventing one.
        $this->assertArrayNotHasKey('conversation', $draft);
        $this->assertSame(['id' => 'bob', 'displayName' => 'Bob Souza'], $draft['target']);
        $this->assertSame('oi', $draft['draft']['message']);
    }

    public function testAGroupDraftNamesTheGroupAndTheGuestsAndCarriesNoConversation(): void {
        $draft = $this->drafts->group(
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
        $this->assertStringContainsString('approval_id igual ao approvalId acima', $draft['message']);
    }

    public function testAGroupDraftWithoutGuestsDoesNotPromiseAnyInvitation(): void {
        $draft = $this->drafts->group('alice', 'Projeto X', []);

        $this->assertSame([], $draft['draft']['participants']);
        $this->assertStringNotContainsString('convidados', $draft['message']);
    }

    public function testAGroupApprovalCoversTheNameAndTheGuestListTogether(): void {
        $guests = ['bob', 'carol'];
        $draft = $this->drafts->group('alice', 'Projeto X', [
            new DirectContact('bob', 'Bob Souza'),
            new DirectContact('carol', 'Carol Lima'),
        ]);

        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', $guests);

        // A different name is another group: the one the user saw is the one that gets created.
        try {
            $this->drafts->approveGroup('alice', $draft['approvalId'], 'Outro grupo', $guests);
            $this->fail('an approval covered a group the user never saw');
        } catch (ApprovalException $e) {
            $this->assertSame(Messages::APPROVAL_INVALID, $e->getMessage());
        }
    }

    public function testAGroupApprovalIsSpentByTheFirstConfirmedCall(): void {
        $draft = $this->drafts->group('alice', 'Projeto X', [new DirectContact('bob', 'Bob Souza')]);

        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', ['bob']);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', ['bob']);
    }

    public function testARepeatedGuestInTheConfirmedCallIsTheSameApprovedList(): void {
        $draft = $this->drafts->group('alice', 'Projeto X', [new DirectContact('bob', 'Bob Souza')]);

        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', ['bob', 'bob']);

        $this->expectException(ApprovalException::class);
        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', ['bob']);
    }

    public function testAGroupDraftCannotDropAGuestToCreateItWithoutThem(): void {
        $draft = $this->drafts->group('alice', 'Projeto X', [new DirectContact('bob', 'Bob Souza')]);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveGroup('alice', $draft['approvalId'], 'Projeto X', []);
    }

    public function testABlankGroupNameIsAClientMistakeBeforeAnyPreview(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::INVALID_GROUP_NAME);

        $this->drafts->group('alice', '   ', []);
    }

    public function testABatchDraftShowsEveryItemWithTheExactTextAndTheQuotedAnswer(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $draft = $this->drafts->batch($this->givenConversation(), 'alice', [
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

    public function testABatchApprovalCoversTheWholeListAndNothingElse(): void {
        $conversation = $this->givenConversation();
        $items = [['message' => 'primeiro'], ['message' => 'segundo', 'replyTo' => 7]];
        $draft = $this->drafts->batch($conversation, 'alice', $items);

        // Same list: the batch is approved.
        $this->drafts->approveBatch($conversation, 'alice', $draft['approvalId'], $items);

        // A shorter batch is not that draft: dropping an item is exactly what the approval forbids.
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveBatch($conversation, 'alice', $draft['approvalId'], [['message' => 'primeiro']]);
    }

    public function testABatchApprovalIsSpentByTheFirstConfirmedCall(): void {
        $conversation = $this->givenConversation();
        $items = [['message' => 'primeiro']];
        $draft = $this->drafts->batch($conversation, 'alice', $items);
        $this->drafts->approveBatch($conversation, 'alice', $draft['approvalId'], $items);

        try {
            $this->drafts->approveBatch($conversation, 'alice', $draft['approvalId'], $items);
            $this->fail('a spent batch approval was accepted twice');
        } catch (ApprovalException $e) {
            $this->assertSame(Messages::APPROVAL_INVALID, $e->getMessage());
        }
    }

    public function testAnEmptyOrOversizedBatchIsAClientMistake(): void {
        $conversation = $this->givenConversation();

        try {
            $this->drafts->batch($conversation, 'alice', []);
            $this->fail('an empty batch was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::EMPTY_BATCH, $e->getMessage());
        }

        $tooMany = array_fill(0, DraftApproval::MAX_BATCH + 1, ['message' => 'oi']);
        try {
            $this->drafts->batch($conversation, 'alice', $tooMany);
            $this->fail('a batch over the limit was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(sprintf(Messages::TOO_MANY_MESSAGES, DraftApproval::MAX_BATCH), $e->getMessage());
        }
    }

    public function testABatchWithABlankItemIsRefusedBeforeAnyCitationIsRead(): void {
        $conversation = $this->givenConversation();
        $this->writer->expects($this->never())->method('quoteTarget');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::EMPTY_MESSAGE);
        $this->drafts->batch($conversation, 'alice', [
            ['message' => 'primeiro', 'replyTo' => 7],
            ['message' => '   '],
        ]);
    }

    public function testTheDirectMessageDraftNamesThePersonInTheInstruction(): void {
        $draft = $this->drafts->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi');

        $this->assertSame(sprintf(Messages::CONFIRMATION_INSTRUCTION_TARGET, 'Bob Souza'), $draft['message']);
        $this->assertStringContainsString('approval_id', $draft['message']);
    }

    public function testAnApprovedDirectMessageHasToMatchTheTargetAndTheTextOfItsDraft(): void {
        $draft = $this->drafts->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi');
        $id = $draft['approvalId'];

        // Same draft, same arguments: the approval is spent by the first one.
        $this->drafts->approveDirectMessage('alice', $id, 'bob', 'oi');

        try {
            $this->drafts->approveDirectMessage('alice', $id, 'bob', 'oi');
            $this->fail('a spent approval was accepted twice');
        } catch (ApprovalException $e) {
            $this->assertSame(Messages::APPROVAL_INVALID, $e->getMessage());
        }
    }

    public function testADirectMessageDraftCannotBeApprovedWithAnotherTargetOrAnotherText(): void {
        foreach ([['carol', 'oi'], ['bob', 'outro texto']] as [$target, $text]) {
            $draft = $this->drafts->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi');

            try {
                $this->drafts->approveDirectMessage('alice', $draft['approvalId'], $target, $text);
                $this->fail("a draft of bob was approved for $target with text '$text'");
            } catch (ApprovalException $e) {
                $this->assertSame(Messages::APPROVAL_INVALID, $e->getMessage());
            }
        }
    }

    public function testADirectMessageDraftOfOneUserIsNoApprovalForAnother(): void {
        $draft = $this->drafts->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi');

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveDirectMessage('mallory', $draft['approvalId'], 'bob', 'oi');
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
        $share = $this->givenShare();
        $this->attachmentAccess->expects($this->once())
            ->method('requireRoomShareOf')
            ->with($conversation, 'alice', 77)
            ->willReturn($share);
        $this->attachmentAccess->expects($this->once())
            ->method('describe')
            ->with($share)
            ->willReturn(['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1024, 'mimeType' => 'image/png']);

        $draft = $this->drafts->quote($conversation, 'alice', 77, ' a figura ');

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

        $draft = $this->drafts->quote($this->givenConversation(), 'alice', 77, null);

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

        $this->drafts->quote($this->givenConversation(), 'alice', 77, null);

        // Naming the file must not touch the share: the card itself is the only thing that publishes one.
        $this->addToAssertionCount(1);
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

    public function testEveryDraftHandsOutTheIdTheApprovedCallHasToCarry(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')->willReturn([
            'attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png',
        ]);

        foreach (
            [
                $this->drafts->reply($this->givenConversation(), 'alice', 'oi', null),
                $this->drafts->attach($this->givenConversation(), 'alice', 'relatorio.pdf', null),
                $this->drafts->quote($this->givenConversation(), 'alice', 77, null),
            ] as $draft
        ) {
            $this->assertNotSame('', $draft['approvalId']);
            $this->assertTrue($draft['requiresConfirmation']);
        }
    }

    public function testTheApprovedReplyIsTheOneThatWasDrafted(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', ' bom dia ', 42);

        $this->drafts->approveReply($this->givenConversation(), 'alice', $draft['approvalId'], ' bom dia ', 42);

        $this->addToAssertionCount(1);
    }

    public function testTheSameApprovalCannotBeSpentTwice(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'bom dia', null);
        $this->drafts->approveReply($this->givenConversation(), 'alice', $draft['approvalId'], 'bom dia', null);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveReply($this->givenConversation(), 'alice', $draft['approvalId'], 'bom dia', null);
    }

    public function testAnApprovalDoesNotCoverAMessageTheUserNeverSaw(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'bom dia', null);

        // Same call, different text: the user approved "bom dia", not this.
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveReply($this->givenConversation(), 'alice', $draft['approvalId'], 'outra coisa', null);
    }

    public function testAnApprovalDoesNotCoverAnotherConversation(): void {
        $draft = $this->drafts->reply($this->givenConversation('abcd'), 'alice', 'bom dia', null);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveReply($this->givenConversation('zzzz'), 'alice', $draft['approvalId'], 'bom dia', null);
    }

    public function testAnApprovalOfOneConversationDoesNotApproveAQuoteInAnother(): void {
        $draft = $this->drafts->reply($this->givenConversation('abcd'), 'alice', 'bom dia', null);

        // Same message and same account, but a different action: approval is per tool, not per account.
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveQuote($this->givenConversation('abcd'), 'alice', $draft['approvalId'], 77, null);
    }

    public function testAnApprovalDoesNotTravelToAnotherAccount(): void {
        $draft = $this->drafts->reply($this->givenConversation(), 'alice', 'bom dia', null);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveReply($this->givenConversation(), 'mallory', $draft['approvalId'], 'bom dia', null);
    }

    public function testACallThatSkipsTheDraftHasNothingToApprove(): void {
        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_MISSING);
        $this->drafts->approveReply($this->givenConversation(), 'alice', null, 'bom dia', null);
    }

    public function testTheApprovedAttachIsTheFileThatWasDrafted(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $draft = $this->drafts->attach($this->givenConversation(), 'alice', 'relatorio.pdf', 'olha ');

        $this->drafts->approveAttach($this->givenConversation(), 'alice', $draft['approvalId'], 'relatorio.pdf', 'olha ');

        $this->addToAssertionCount(1);
    }

    public function testAnApprovalDoesNotCoverAnotherFile(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $draft = $this->drafts->attach($this->givenConversation(), 'alice', 'relatorio.pdf', null);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveAttach($this->givenConversation(), 'alice', $draft['approvalId'], 'outro.pdf', null);
    }

    public function testTheApprovedQuoteIsTheAttachmentThatWasDrafted(): void {
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')->willReturn([
            'attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png',
        ]);
        $draft = $this->drafts->quote($this->givenConversation(), 'alice', 77, ' a figura ');

        $this->drafts->approveQuote($this->givenConversation(), 'alice', $draft['approvalId'], 77, ' a figura ');

        $this->addToAssertionCount(1);
    }

    public function testAnApprovalDoesNotCoverAnotherAttachment(): void {
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')->willReturn([
            'attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png',
        ]);
        $draft = $this->drafts->quote($this->givenConversation(), 'alice', 77, null);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage(Messages::APPROVAL_INVALID);
        $this->drafts->approveQuote($this->givenConversation(), 'alice', $draft['approvalId'], 88, null);
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
        return new Conversation(new DraftRoomStub($token, 'Comercial'), new DraftParticipantStub());
    }

    private function givenShare(): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('77');

        return $share;
    }

    /** In-memory app config, so a pending approval survives the two calls the way it does in production. */
    private function givenAppConfig(): IAppConfig&MockObject {
        $rows = [];
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$rows): string {
                return $rows[$key] ?? $default;
            });

        $config->method('setValueString')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$rows): bool {
                $rows[$key] = $value;

                return true;
            });
        $config->method('deleteKey')
            ->willReturnCallback(static function (string $app, string $key) use (&$rows): void {
                unset($rows[$key]);
            });
        $config->method('getKeys')
            ->willReturnCallback(static fn (string $app): array => array_keys($rows));

        return $config;
    }

    private function givenRandom(): ISecureRandom&MockObject {
        $issued = 0;
        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')
            ->willReturnCallback(static function () use (&$issued): string {
                return sprintf('D%031d', ++$issued);
            });

        return $random;
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