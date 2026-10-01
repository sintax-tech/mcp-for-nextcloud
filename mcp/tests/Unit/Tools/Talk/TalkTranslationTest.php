<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\ReferenceLinker;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\WritePreview;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the user of the Talk module sees comes out in the language of the account; what the model reads
 * (the tool descriptions) stays English in every language.
 */
final class TalkTranslationTest extends TestCase {
    /** @var array<string, string> method of Messages and the pt-BR text the app showed before the translation */
    private const PORTUGUESE = [
        'referenceLabelDeck' => 'Card do Deck',
        'referenceLabelCalendar' => 'Evento do Calendar',
        'referenceUntitled' => '(sem título)',
        'conversationNotFound' => 'conversa não encontrada ou sem acesso',
        'conversationNotWritable' => 'sem permissão para escrever nesta conversa',
        'fileNotFound' => 'arquivo não encontrado ou sem acesso',
        'attachmentNotFound' => 'anexo não encontrado nesta conversa',
        'userNotReachable' => 'usuário não encontrado ou sem permissão para falar com ele',
        'invitationFailed' => 'o Talk não aceitou o convite deste participante',
        'referenceNotFound' => 'item referenciado não encontrado ou sem acesso',
        'referenceDeckOff' => 'referência ao Deck indisponível: o app Deck está desligado para você ou o MCP não tem permissão de leitura no Deck',
        'referenceCalendarOff' => 'referência ao Calendar indisponível: o app Calendar está desligado para você ou o MCP não tem permissão de leitura no Calendar',
        'conversationNotCreated' => 'conversa não criada pelo Talk',
        'messageNotSent' => 'não foi possível enviar a mensagem',
        'fileNotShared' => 'não foi possível compartilhar o arquivo',
        'fileAlreadyShared' => 'o arquivo já está compartilhado nesta conversa',
        'captionNotSent' => 'O anexo foi compartilhado nesta conversa, mas a legenda não foi enviada. Não repita talk_attach_file: o arquivo já está compartilhado e um novo cartão não seria criado. Se o usuário ainda quiser a legenda, envie-a como uma nova mensagem com talk_reply, seguindo a mesma aprovação.',
        'talkUnavailable' => 'o app de conversas não está disponível',
        'unexpected' => 'erro inesperado ao acessar o Talk',
        'invalidToken' => 'conversation_token inválido',
        'emptyMessage' => 'message não pode ser vazia',
        'emptyBatch' => 'informe ao menos uma mensagem em messages',
        'messageTooLong' => 'message excede o limite de caracteres',
        'invalidPath' => 'path inválido',
        'invalidUser' => 'user inválido',
        'invalidReference' => 'reference inválida: use {type: deck_card, card_id, board_id?} ou {type: calendar_event, calendar, uid}',
        'invalidGroupName' => 'name do grupo não pode ser vazio',
        'groupNameTooLong' => 'name do grupo excede o limite de caracteres',
        'invalidIdentifier' => 'identificador inválido',
        'invalidLimit' => 'limit deve ser um número entre 1 e 200',
        'replyTargetNotFound' => 'mensagem citada não encontrada nesta conversa',
        'unknownTool' => 'ferramenta desconhecida',
    ];

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testPortugueseUserGetsTheSameTextsAsBefore(): void {
        Translator::use(new JsonL10n('pt_BR'));
        foreach (self::PORTUGUESE as $method => $text) {
            self::assertSame($text, [Messages::class, $method](), $method);
        }
        self::assertSame(
            'Vou criar no Talk o grupo \'Projeto X\' com você como dono e como convidados Bob Souza, Carol Lima.'
            . ' Mostre este plano ao usuário e só repita a mesma chamada com confirm: true depois que ele disser'
            . ' sim de forma explícita; se ele pedir mudanças, ajuste o plano e chame a tool de novo.'
            . ' Sem essa confirmação o servidor não cria nada.',
            Messages::confirmationInstructionGroup('Projeto X', Messages::invitedGuests(['Bob Souza', 'Carol Lima'])),
        );
        self::assertSame(
            'Mostre este plano ao usuário exatamente como será enviado para a conversa \'Comercial\', pergunte se ele'
            . ' realmente quer isso e só repita a mesma chamada com confirm: true depois que ele disser sim de forma'
            . ' explícita. Se ele pedir mudanças, ajuste o texto, chame a tool de novo e mostre o novo plano. Sem essa'
            . ' confirmação o servidor não publica nada.',
            Messages::confirmationInstruction('Comercial'),
        );
        self::assertSame(
            'Mostre este plano ao usuário exatamente como será enviado em uma conversa direta com \'Bob Souza\','
            . ' pergunte se ele realmente quer isso e só repita a mesma chamada com confirm: true depois que ele disser'
            . ' sim de forma explícita. Se ele pedir mudanças, ajuste o texto, chame a tool de novo e mostre o novo'
            . ' plano. Sem essa confirmação o servidor não publica nada e a conversa direta não é criada.',
            Messages::confirmationInstructionTarget('Bob Souza'),
        );
        self::assertSame(
            'participante \'ghost\' não encontrado ou sem permissão para falar com ele',
            Messages::participantNotReachable('ghost'),
        );
        self::assertSame('lote com mais de 50 mensagens', Messages::tooManyMessages(50));
        self::assertSame('grupo com mais de 10 participantes', Messages::tooManyParticipants(10));
    }

    public function testSpanishUserGetsSpanishTexts(): void {
        $english = [];
        foreach (array_keys(self::PORTUGUESE) as $method) {
            $english[$method] = [Messages::class, $method]();
        }
        Translator::use(new JsonL10n('es'));
        foreach ($english as $method => $source) {
            $translated = [Messages::class, $method]();
            self::assertNotSame($source, $translated, $method . ' has no Spanish text');
            self::assertNotSame('', $translated);
        }
        self::assertSame(
            'conversación no encontrada o sin acceso',
            Messages::conversationNotFound(),
        );
    }

    public function testWithoutTranslatorOrWithUnknownLanguageTheMessagesAreEnglish(): void {
        self::assertSame('invalid path', Messages::invalidPath());
        Translator::use(new JsonL10n('de'));
        self::assertSame('invalid path', Messages::invalidPath());
    }

    /** A refusal reaches the user in their own language, because the text is translated when it is built. */
    public function testAFailureReachesTheUserTranslated(): void {
        Translator::use(new JsonL10n('pt_BR'));
        try {
            throw new ConversationAccessException(Messages::conversationNotWritable());
        } catch (ConversationAccessException $e) {
            self::assertSame('sem permissão para escrever nesta conversa', $e->getMessage());
        }
    }

    /** The descriptions are read by the model, so they never change with the language of the account. */
    public function testDescriptionsAreFixedEnglish(): void {
        $module = $this->module();
        Translator::use(new JsonL10n('pt_BR'));
        $definitions = $module->definitions();
        $texts = [];
        array_walk_recursive($definitions, static function ($value, $key) use (&$texts): void {
            if ($key === 'description' && is_string($value)) {
                $texts[] = $value;
            }
        });
        self::assertNotEmpty($texts);
        foreach ($texts as $text) {
            self::assertDoesNotMatchRegularExpression('/[À-ÿ]/u', $text);
        }
        self::assertSame(Messages::TOOL_REPLY, $definitions[2]['description']);
    }

    private function module(): TalkModule {
        return new TalkModule(
            $this->createMock(TalkServices::class),
            $this->createMock(ConversationReader::class),
            $this->createMock(ConversationResolver::class),
            $this->createMock(ConversationWriter::class),
            $this->createMock(FileSharer::class),
            $this->createMock(WritePreview::class),
            $this->createMock(UserConversationResolver::class),
            $this->createMock(GroupCreator::class),
            $this->createMock(ReferenceLinker::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}
