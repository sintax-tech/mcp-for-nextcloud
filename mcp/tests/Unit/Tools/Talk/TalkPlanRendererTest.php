<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkPlanRenderer;
use OCA\Mcp\Tools\Talk\WritePreview;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The plan of a Talk write is the only thing the user sees before a message leaves their account, so it has to be
 * written for that person: the conversation or the person by name, the text as a short excerpt, the file by its own
 * name. What the tests pin is that a renderer never invents a field the plan does not carry, so it answers null and
 * lets the generic body speak instead of showing something the confirmed call would not do.
 *
 * Every test builds the plan with the real WritePreview over doubles, exactly as the module does: a renderer written
 * against a hand-made payload would keep passing while the module moved a key.
 */
final class TalkPlanRendererTest extends TestCase {
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
        $this->previews = new WritePreview(
            $this->writer,
            $this->sharer,
            $this->attachmentAccess,
            new ActorNames($this->userManager),
        );
    }

    protected function tearDown(): void {
        Translator::reset();
        parent::tearDown();
    }

    public function testAReplyNamesTheConversationAndShowsTheTextAsAnExcerpt(): void {
        $body = TalkPlanRenderer::render(
            'talk_reply',
            $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null),
        );

        $this->assertSame("Message to **Comercial**:\n\n> bom dia", $body);
        // The token is what the module needs; the person reading this knows the conversation by its name.
        $this->assertStringNotContainsString('abcd', (string)$body);
    }

    public function testALongMessageBecomesAnExcerptAndSaysSoWasCut(): void {
        $body = TalkPlanRenderer::render(
            'talk_reply',
            $this->previews->reply($this->givenConversation(), 'alice', str_repeat('a', 500), null),
        );

        // The text is cut, not hidden: the user has to know they are approving a beginning of a long message.
        $this->assertStringContainsString(Messages::contentTruncated(), (string)$body);
        $this->assertStringContainsString(str_repeat('a', TalkPlanRenderer::EXCERPT_MAX_LENGTH), (string)$body);
        $this->assertLessThan(400, mb_strlen((string)$body));
    }

    public function testAMultilineMessageKeepsItsLinesInsideTheExcerpt(): void {
        $body = TalkPlanRenderer::render(
            'talk_reply',
            $this->previews->reply($this->givenConversation(), 'alice', "linha 1\nlinha 2", null),
        );

        $this->assertSame("Message to **Comercial**:\n\n> linha 1\n> linha 2", $body);
    }

    public function testAQuotedReplyNamesWhoIsBeingAnswered(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $body = TalkPlanRenderer::render(
            'talk_reply',
            $this->previews->reply($this->givenConversation(), 'alice', 'concordo', 42),
        );

        $this->assertStringContainsString('Replying to **Bob Souza**:', (string)$body);
        $this->assertStringContainsString('> concordo com o envio', (string)$body);
    }

    public function testAReferenceIsNamedSoTheUserKnowsWhoseItemTheLinkExposes(): void {
        $plan = $this->previews->reply($this->givenConversation(), 'alice', 'olha', null)
            + ['reference' => ['type' => 'deck_card', 'title' => 'Fechamento de março', 'url' => 'https://cloud/deck/1']];

        $body = TalkPlanRenderer::render('talk_reply', $plan);

        $this->assertStringContainsString('Deck card', (string)$body);
        $this->assertStringContainsString('Fechamento de março', (string)$body);
        $this->assertStringNotContainsString('deck_card', (string)$body);
    }

    public function testADirectMessageNamesThePersonAndSaysTheConversationIsCreated(): void {
        $body = TalkPlanRenderer::render(
            'talk_message_user',
            $this->previews->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi'),
        );

        $this->assertStringContainsString('**Bob Souza**', (string)$body);
        $this->assertStringContainsString('> oi', (string)$body);
        // The plan carries no conversation, and that absence is the consequence the user has to know about.
        $this->assertStringContainsString('will be created', (string)$body);
        $this->assertStringNotContainsString('bob', str_replace('Bob Souza', '', (string)$body));
    }

    public function testABatchSaysHowManyMessagesGoWhereAndWhoIsAnswered(): void {
        $this->userManager->method('get')->with('bob')->willReturn($this->givenUser('Bob Souza'));
        $this->givenQuotedComment('42', 'bob', 'concordo com o envio');

        $body = TalkPlanRenderer::render('talk_send_batch', $this->previews->batch(
            $this->givenConversation(),
            'alice',
            [
                ['message' => 'primeiro'],
                ['message' => 'segundo', 'replyTo' => 42],
            ],
        ));

        $this->assertStringContainsString('2', (string)$body);
        $this->assertStringContainsString('**Comercial**', (string)$body);
        $this->assertStringContainsString('> primeiro', (string)$body);
        $this->assertStringContainsString('> segundo', (string)$body);
        $this->assertStringContainsString('**Bob Souza**', (string)$body);
    }

    public function testABatchOfOneStillReadsAsOneMessage(): void {
        $body = TalkPlanRenderer::render('talk_send_batch', $this->previews->batch(
            $this->givenConversation(),
            'alice',
            [['message' => 'única']],
        ));

        $this->assertStringContainsString(Messages::planOneMessage('Comercial'), (string)$body);
    }

    public function testAnAttachmentIsNamedByItsFileNameAndCarriesTheCaption(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048]);

        $body = TalkPlanRenderer::render('talk_attach_file', $this->previews->attach(
            $this->givenConversation(),
            'alice',
            'relatorio.pdf',
            'olha o relatório',
        ));

        $this->assertStringContainsString('**relatorio.pdf**', (string)$body);
        $this->assertStringContainsString('2 kB', (string)$body);
        $this->assertStringContainsString('**Comercial**', (string)$body);
        $this->assertStringContainsString('> olha o relatório', (string)$body);
    }

    public function testAnAttachmentWithoutACaptionShowsNoEmptyCaption(): void {
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 10]);

        $body = TalkPlanRenderer::render('talk_attach_file', $this->previews->attach(
            $this->givenConversation(),
            'alice',
            'relatorio.pdf',
            null,
        ));

        $this->assertStringContainsString('**relatorio.pdf**', (string)$body);
        $this->assertStringNotContainsString('>', (string)$body);
    }

    public function testAQuotedFileIsNamedByItsFileNameAndNotByItsRoomShareId(): void {
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')
            ->willReturn(['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1024, 'mimeType' => 'image/png']);

        $body = TalkPlanRenderer::render('talk_quote_file', $this->previews->quote(
            $this->givenConversation(),
            'alice',
            77,
            'a figura',
        ));

        $this->assertStringContainsString('**figura.png**', (string)$body);
        $this->assertStringContainsString('> a figura', (string)$body);
        // 77 alone would tell nobody what is about to be cited.
        $this->assertStringNotContainsString('77', (string)$body);
    }

    public function testAQuoteWhoseNameIsUnknownFallsBackToTheConversationInsteadOfTheId(): void {
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')
            ->willReturn(['attachmentId' => 77, 'name' => null, 'size' => null, 'mimeType' => null]);

        $body = TalkPlanRenderer::render('talk_quote_file', $this->previews->quote(
            $this->givenConversation(),
            'alice',
            77,
            null,
        ));

        $this->assertStringContainsString('**Comercial**', (string)$body);
        $this->assertStringNotContainsString('77', (string)$body);
    }

    public function testAGroupNamesItselfAndListsWhoIsInvited(): void {
        $body = TalkPlanRenderer::render('talk_create_group', $this->previews->group(
            'alice',
            'Projeto X',
            [new DirectContact('bob', 'Bob Souza'), new DirectContact('carol', 'Carol Lima')],
        ));

        $this->assertStringContainsString('**Projeto X**', (string)$body);
        $this->assertStringContainsString('**Bob Souza**', (string)$body);
        $this->assertStringContainsString('**Carol Lima**', (string)$body);
    }

    public function testAGroupWithoutGuestsSaysNobodyIsInvited(): void {
        $body = TalkPlanRenderer::render(
            'talk_create_group',
            $this->previews->group('alice', 'Projeto X', []),
        );

        $this->assertStringContainsString('**Projeto X**', (string)$body);
        $this->assertStringContainsString(Messages::planNoGuests(), (string)$body);
    }

    public function testAnUnknownToolOrAnEmptyPlanAnswersNullSoTheGenericBodySpeaks(): void {
        $this->assertNull(TalkPlanRenderer::render('talk_list_conversations', ['requiresConfirmation' => true]));
        $this->assertNull(TalkPlanRenderer::render('talk_reply', []));
        $this->assertNull(TalkPlanRenderer::render('talk_send_batch', []));
        $this->assertNull(TalkPlanRenderer::render('talk_attach_file', []));
        $this->assertNull(TalkPlanRenderer::render('talk_quote_file', []));
        $this->assertNull(TalkPlanRenderer::render('talk_message_user', []));
        $this->assertNull(TalkPlanRenderer::render('talk_create_group', []));
    }

    /** A plan missing the field the renderer needs is not something to guess: the generic body is the honest answer. */
    public function testAPlanWithoutTheFieldTheRendererNeedsAnswersNull(): void {
        $this->assertNull(TalkPlanRenderer::render('talk_reply', [
            'conversation' => ['displayName' => 'Comercial'],
            'draft' => ['message' => 42],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_reply', [
            'conversation' => ['token' => 'abcd'],
            'draft' => ['message' => 'oi'],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_message_user', [
            'target' => ['id' => 'bob'],
            'draft' => ['message' => 'oi'],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_attach_file', [
            'conversation' => ['displayName' => 'Comercial'],
            'draft' => ['file' => ['path' => '/a.pdf']],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_create_group', [
            'draft' => ['participants' => []],
        ]));
    }

    /** A render failure is never shown to the user: the generic body has to be able to take over. */
    public function testAFieldOfAnUnexpectedTypeDoesNotThrow(): void {
        $this->assertNull(TalkPlanRenderer::render('talk_send_batch', [
            'conversation' => ['displayName' => 'Comercial'],
            'draft' => ['messages' => 'not a list'],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_reply', [
            'conversation' => 'Comercial',
            'draft' => ['message' => 'oi'],
        ]));
    }

    public function testAPortugueseUserReadsTheSameBodyInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));

        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 2048]);

        $attach = (string)TalkPlanRenderer::render('talk_attach_file', $this->previews->attach(
            $this->givenConversation(),
            'alice',
            'relatorio.pdf',
            'olha',
        ));
        $direct = (string)TalkPlanRenderer::render('talk_message_user', $this->previews->directMessage(
            new DirectContact('bob', 'Bob Souza'),
            'alice',
            'oi',
        ));
        $batch = (string)TalkPlanRenderer::render('talk_send_batch', $this->previews->batch(
            $this->givenConversation(),
            'alice',
            [['message' => 'primeiro'], ['message' => 'segundo']],
        ));
        $group = (string)TalkPlanRenderer::render(
            'talk_create_group',
            $this->previews->group('alice', 'Projeto X', [new DirectContact('bob', 'Bob Souza')]),
        );

        // The names the person recognizes stay as they are; only the prose around them is translated.
        $this->assertStringContainsString('relatorio.pdf', $attach);
        $this->assertStringContainsString('**Comercial**', $attach);
        $this->assertStringContainsString('olha', $attach);
        $this->assertStringNotContainsString('will be shared', $attach);

        $this->assertStringContainsString('Bob Souza', $direct);
        $this->assertStringNotContainsString('will be created', $direct);

        $this->assertStringContainsString('Comercial', $batch);
        $this->assertStringNotContainsString('Message', $batch);

        $this->assertStringContainsString('Projeto X', $group);
        $this->assertStringNotContainsString('Talk group', $group);
    }

    public function testASpanishUserReadsTheSameBodyInSpanish(): void {
        Translator::use(new JsonL10n('es'));

        $body = (string)TalkPlanRenderer::render(
            'talk_reply',
            $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null),
        );

        $this->assertStringContainsString('Comercial', $body);
        $this->assertStringNotContainsString('Message to', $body);
    }

    /** Whatever the language, the payload is Markdown the client can show as is. */
    public function testTheBodyNeverCarriesTheKeysOrTheConfirmationInstructionOfThePlan(): void {
        $plan = $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null);

        $body = (string)TalkPlanRenderer::render('talk_reply', $plan);

        foreach (['requiresConfirmation', 'action', 'conversation', 'draft', 'confirm: true'] as $noise) {
            $this->assertStringNotContainsString($noise, $body);
        }
    }

    /** Rendering is a read of an array: it must not reach the conversation, the file or the quote target. */
    public function testRenderingARealPlanPublishesNothingAndResolvesNothing(): void {
        $conversation = $this->givenConversation();
        $this->sharer->method('previewFile')
            ->willReturn(['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1]);
        $this->attachmentAccess->method('requireRoomShareOf')->willReturn($this->givenShare());
        $this->attachmentAccess->method('describe')
            ->willReturn(['attachmentId' => 77, 'name' => 'figura.png', 'size' => 1, 'mimeType' => 'image/png']);

        $plans = [
            $this->previews->reply($conversation, 'alice', 'oi', null),
            $this->previews->batch($conversation, 'alice', [['message' => 'oi']]),
            $this->previews->attach($conversation, 'alice', 'relatorio.pdf', null),
            $this->previews->quote($conversation, 'alice', 77, null),
            $this->previews->directMessage(new DirectContact('bob', 'Bob Souza'), 'alice', 'oi'),
            $this->previews->group('alice', 'Projeto X', []),
        ];

        $this->writer->expects($this->never())->method('reply');
        $this->writer->expects($this->never())->method('quoteTarget');
        $this->sharer->expects($this->never())->method('attach');

        foreach ($plans as $plan) {
            $this->assertNotNull(TalkPlanRenderer::render((string)$plan['action'], $plan));
        }
    }

    /** A plan the module would refuse never reaches a renderer; one a renderer cannot read must not throw either. */
    public function testAPlanWithAPathologicalFieldIsAnsweredWithNullInsteadOfAnError(): void {
        $this->assertNull(TalkPlanRenderer::render('talk_create_group', [
            'draft' => ['name' => 'Projeto X', 'participants' => 'todos'],
        ]));
        $this->assertNull(TalkPlanRenderer::render('talk_quote_file', [
            'conversation' => ['displayName' => 'Comercial'],
            'draft' => ['file' => 'figura.png'],
        ]));
    }

    /** The renderer is called from the envelope, which cannot afford an exception in the middle of a plan. */
    public function testAThrowingTranslatorDoesNotTakeThePlanDown(): void {
        // The plan is built first: a broken translator is the envelope's problem to survive, not a preview's.
        $plan = $this->previews->reply($this->givenConversation(), 'alice', 'bom dia', null);

        Translator::use(new class implements \OCP\IL10N {
            public function t(string $text, $parameters = []): string {
                throw new RuntimeException('translation unavailable');
            }

            public function n(string $text_singular, string $text_plural, $count, $parameters = []): string {
                throw new RuntimeException('translation unavailable');
            }

            public function l($type, $data, $options = []) {
                return (string)$data;
            }

            public function getLanguageCode(): string {
                return 'pt_BR';
            }

            public function getLocaleCode(): string {
                return 'pt_BR';
            }
        });

        $this->assertNull(TalkPlanRenderer::render('talk_reply', $plan));
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
        return new Conversation(new RenderRoomStub($token, 'Comercial'), new RenderParticipantStub());
    }

    private function givenShare(): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getId')->willReturn('77');

        return $share;
    }
}

final class RenderRoomStub {
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

final class RenderParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}