<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DraftApproval;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TalkModuleTest extends TestCase {
    private ConversationReader&MockObject $reader;
    private ConversationResolver&MockObject $resolver;
    private ConversationWriter&MockObject $writer;
    private FileSharer&MockObject $sharer;
    private DraftApproval&MockObject $draftApproval;
    private LoggerInterface&MockObject $logger;
    private TalkModule $module;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = $this->createMock(ConversationReader::class);
        $this->resolver = $this->createMock(ConversationResolver::class);
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->draftApproval = $this->createMock(DraftApproval::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->module = new TalkModule(
            $this->createMock(TalkServices::class),
            $this->reader,
            $this->resolver,
            $this->writer,
            $this->sharer,
            $this->draftApproval,
            $this->logger,
        );
    }

    public function testTheModuleAdvertisesExactlyTheFiveToolsOfTheContract(): void {
        $this->assertSame(
            [
                'talk_list_conversations' => 'read',
                'talk_read_messages' => 'read',
                'talk_reply' => 'reply',
                'talk_attach_file' => 'attach',
                'talk_quote_file' => 'quote',
            ],
            array_column($this->module->definitions(), 'operation', 'name'),
        );
    }

    public function testEveryToolIsGrantedByTheTalkModuleAndGatedOnSpreed(): void {
        foreach ($this->module->definitions() as $definition) {
            $this->assertSame('talk', $definition['module'], $definition['name'] . ' must belong to the talk module.');
            $this->assertSame('spreed', $definition['app'], $definition['name'] . ' must be gated on spreed.');
            $this->assertNotSame('', $definition['description'], $definition['name'] . ' needs a description.');
            $this->assertIsString(json_encode($definition['inputSchema']), $definition['name'] . ' schema must be JSON.');
            $this->assertFalse($definition['inputSchema']['additionalProperties']);
        }
    }

    public function testSchemasCarryTheLimitsAndTheRequiredArgumentsOfTheContract(): void {
        $schemas = array_column($this->module->definitions(), 'inputSchema', 'name');

        // An object, so the schema serializes as {} rather than [].
        $this->assertSame('{}', json_encode($schemas['talk_list_conversations']['properties']));
        $this->assertArrayNotHasKey('required', $schemas['talk_list_conversations']);
        $this->assertSame(['conversation_token'], $schemas['talk_read_messages']['required']);
        $this->assertSame(1, $schemas['talk_read_messages']['properties']['limit']['minimum']);
        $this->assertSame(200, $schemas['talk_read_messages']['properties']['limit']['maximum']);
        $this->assertSame(50, $schemas['talk_read_messages']['properties']['limit']['default']);
        $this->assertSame(['conversation_token', 'message'], $schemas['talk_reply']['required']);
        $this->assertSame(['conversation_token', 'path'], $schemas['talk_attach_file']['required']);
        $this->assertSame(['conversation_token', 'attachment_id'], $schemas['talk_quote_file']['required']);
        $this->assertArrayNotHasKey('message', $schemas['talk_attach_file']['required']);
        $this->assertArrayNotHasKey('message', $schemas['talk_quote_file']['required']);
        // confirm is what turns a draft into a send, so it is a boolean the caller may omit: an absent one is
        // the draft, never an error, and only an explicit true publishes.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file'] as $name) {
            $this->assertSame(['type' => 'boolean'], $schemas[$name]['properties']['confirm'], $name);
            $this->assertArrayNotHasKey('confirm', $schemas[$name]['required'], $name);
        }
        foreach ($schemas as $name => $schema) {
            // The listing tool takes no arguments at all, so it has no token to bound.
            if (isset($schema['properties']) && is_array($schema['properties'])
                && isset($schema['properties']['conversation_token'])) {
                $this->assertSame(30, $schema['properties']['conversation_token']['maxLength'], $name);
            }
        }
    }

    public function testListingAnswersWithTheConversationsOfTheUser(): void {
        $this->reader->expects($this->once())->method('listConversations')->with('alice')->willReturn([
            ['token' => 'abcd', 'displayName' => 'Sala nova'],
        ]);

        $result = $this->module->call(TalkModule::TOOL_LIST, [], 'alice');

        $this->assertSame([
            'conversations' => [['token' => 'abcd', 'displayName' => 'Sala nova']],
        ], $this->payloadOf($result));
        $this->assertArrayNotHasKey('isError', $result);
    }

    public function testReadingForwardsTheTokenAndTheDefaultLimit(): void {
        // The reader resolves the conversation itself, so the module never touches the write path to read.
        $this->resolver->expects($this->never())->method('resolveForReading');
        $this->reader->expects($this->once())
            ->method('readMessages')
            ->with('alice', 'abcd', ConversationReader::DEFAULT_MESSAGES)
            ->willReturn([['id' => '42']]);

        $result = $this->module->call(TalkModule::TOOL_READ, ['conversation_token' => 'abcd'], 'alice');

        $this->assertSame(['messages' => [['id' => '42']]], $this->payloadOf($result));
    }

    public function testReadingForwardsTheRequestedLimit(): void {
        $this->reader->expects($this->once())->method('readMessages')->with('alice', 'abcd', 10);

        $this->module->call(TalkModule::TOOL_READ, ['conversation_token' => 'abcd', 'limit' => 10], 'alice');
    }

    public function testReplyIsRoutedToAWritableConversationWithTheQuotedId(): void {
        $conversation = $this->givenConversation();
        $this->resolver->expects($this->once())
            ->method('resolveForWriting')
            ->with('alice', 'abcd')
            ->willReturn($conversation);
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', 'bom dia', 42)
            ->willReturn(['conversation_token' => 'abcd', 'messageId' => 55]);

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'reply_to' => 42, 'confirm' => true],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'abcd', 'messageId' => 55], $this->payloadOf($result));
    }

    public function testReplyWithoutAQuotedIdPassesNoParent(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($this->anything(), 'alice', 'bom dia', null);

        $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'confirm' => true],
            'alice',
        );
    }

    public function testAttachingForwardsThePathAndTheOptionalCaption(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->sharer->expects($this->once())
            ->method('attach')
            ->with($conversation, 'alice', 'relatorio.pdf', 'olha o relatório')
            ->willReturn(['conversation_token' => 'abcd', 'attachmentId' => 77]);
        $this->writer->expects($this->never())->method('quoteAttachment');

        $result = $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf', 'message' => 'olha o relatório', 'confirm' => true],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'abcd', 'attachmentId' => 77], $this->payloadOf($result));
    }

    public function testAttachingWithoutACaptionPassesNothingToTheCaptionMessage(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->sharer->expects($this->once())
            ->method('attach')
            ->with($this->anything(), 'alice', 'relatorio.pdf', null);

        $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf', 'confirm' => true],
            'alice',
        );
    }

    public function testQuotingForwardsTheAttachmentIdAndTheOptionalCaption(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->writer->expects($this->once())
            ->method('quoteAttachment')
            ->with($conversation, 'alice', 77, 'a figura')
            ->willReturn(['conversation_token' => 'abcd', 'messageId' => 88, 'attachmentId' => 77]);
        $this->sharer->expects($this->never())->method('attach');

        $result = $this->module->call(
            TalkModule::TOOL_QUOTE,
            ['conversation_token' => 'abcd', 'attachment_id' => 77, 'message' => 'a figura', 'confirm' => true],
            'alice',
        );

        $this->assertSame(77, $this->payloadOf($result)['attachmentId']);
    }

    public function testEveryWritingToolAsksTheAgentForTheApprovalInItsDescription(): void {
        $descriptions = array_column($this->module->definitions(), 'description', 'name');

        // The description is the only thing the agent reads before it decides to call the tool, so the rule
        // lives in the sentence itself and not only in this test.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file'] as $name) {
            $this->assertStringContainsString(
                'Antes de enviar, o agente DEVE mostrar o rascunho ao usuário e obter aprovação explícita;'
                    . ' só então repetir com confirm: true.',
                $descriptions[$name],
                $name,
            );
        }

        foreach (['talk_list_conversations', 'talk_read_messages'] as $name) {
            $this->assertStringNotContainsString('confirm: true', $descriptions[$name], $name);
        }
    }

    public function testReplyWithoutConfirmationStopsAtTheDraftAndWritesNothing(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        // The whole point of the confirmation: a call that was not approved must not reach Talk.
        $this->writer->expects($this->never())->method('reply');
        $this->draftApproval->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', 'bom dia', 42)
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_reply']);

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'reply_to' => 42],
            'alice',
        );

        // A draft is an answer, not a failure: the agent has to be able to show it and ask.
        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_reply'], $this->payloadOf($result));
        $this->assertArrayNotHasKey('isError', $result);
    }

    public function testAttachingWithoutConfirmationStopsAtTheDraft(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->sharer->expects($this->never())->method('attach');
        $this->draftApproval->expects($this->once())
            ->method('attach')
            ->with($conversation, 'alice', 'relatorio.pdf', 'olha o relatório')
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_attach_file']);

        $result = $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf', 'message' => 'olha o relatório'],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_attach_file'], $this->payloadOf($result));
    }

    public function testQuotingWithoutConfirmationStopsAtTheDraft(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->writer->expects($this->never())->method('quoteAttachment');
        $this->draftApproval->expects($this->once())
            ->method('quote')
            ->with($conversation, 'alice', 77, 'a figura')
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_quote_file']);

        $result = $this->module->call(
            TalkModule::TOOL_QUOTE,
            ['conversation_token' => 'abcd', 'attachment_id' => 77, 'message' => 'a figura'],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_quote_file'], $this->payloadOf($result));
    }

    public function testAnExplicitFalseIsTheSameAsNoConfirmationAtAll(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->writer->expects($this->never())->method('reply');
        $this->draftApproval->expects($this->once())->method('reply')->willReturn(['requiresConfirmation' => true]);

        $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'confirm' => false],
            'alice',
        );
    }

    public function testRefusalKeepsTheMessageWrittenForTheUser(): void {
        $this->resolver->method('resolveForWriting')
            ->willThrowException(new ConversationAccessException(Messages::CONVERSATION_NOT_WRITABLE));
        // A conversation the user cannot write in is refused even in a draft: there is nothing to approve.
        $this->draftApproval->expects($this->never())->method('reply');
        // A refusal is audited too, so an operator can tell a refused call from one that never arrived.
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), $this->callback(static function (array $context): bool {
                return $context['tool'] === TalkModule::TOOL_REPLY
                    && str_ends_with($context['exception'], 'ConversationAccessException');
            }));

        $result = $this->module->call(TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi'], 'alice');

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::CONVERSATION_NOT_WRITABLE, $result['content'][0]['text']);
    }

    public function testUnavailableSpreedIsAnsweredWithItsOwnMessage(): void {
        $this->resolver->method('resolveForWriting')
            ->willThrowException(new TalkUnavailableException(Messages::TALK_UNAVAILABLE));

        $result = $this->module->call(TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi'], 'alice');

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::TALK_UNAVAILABLE, $result['content'][0]['text']);
    }

    public function testUnexpectedFailureIsGenericAndLoggedWithTheToolAndTheExceptionClass(): void {
        $this->sharer->method('attach')
            ->willThrowException(new RuntimeException('SQLSTATE[42S02] at 10.0.0.9'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    return $context['tool'] === TalkModule::TOOL_ATTACH
                        && str_ends_with($context['exception'], 'RuntimeException')
                        && !str_contains(json_encode($context), 'SQLSTATE')
                        && !str_contains(json_encode($context), '10.0.0.9');
                }),
            );

        $result = $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'a.pdf', 'confirm' => true],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::UNEXPECTED, $result['content'][0]['text']);
    }

    public function testFileRefusalIsAnsweredWithoutReachingTheClient(): void {
        $this->resolver->method('resolveForWriting')->willThrowException(new FileAccessException(Messages::FILE_NOT_FOUND));

        $result = $this->module->call(TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd', 'path' => 'a.pdf'], 'alice');

        $this->assertSame(Messages::FILE_NOT_FOUND, $result['content'][0]['text']);
    }

    public function testClientMistakeIsRaisedForTheProtocolToAnswer(): void {
        $this->resolver->expects($this->never())->method('resolveForWriting');
        $this->logger->expects($this->never())->method('error');

        $this->expectException(InvalidArgumentException::class);
        $this->module->call(TalkModule::TOOL_REPLY, ['message' => 'oi'], 'alice');
    }

    public function testMissingAttachmentIdIsAClientMistake(): void {
        $this->resolver->expects($this->never())->method('resolveForWriting');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::INVALID_IDENTIFIER);
        $this->module->call(TalkModule::TOOL_QUOTE, ['conversation_token' => 'abcd'], 'alice');
    }

    public function testMissingPathIsAClientMistake(): void {
        $this->resolver->expects($this->never())->method('resolveForWriting');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::INVALID_PATH);
        $this->module->call(TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd'], 'alice');
    }

    public function testUnknownToolNameIsAClientMistake(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::UNKNOWN_TOOL);
        $this->module->call('talk_delete_conversation', ['conversation_token' => 'abcd'], 'alice');
    }

    public function testPayloadIsIndentedJsonInASingleTextBlock(): void {
        $this->reader->method('listConversations')->willReturn([]);

        $result = $this->module->call(TalkModule::TOOL_LIST, [], 'alice');

        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertStringContainsString("\n", $result['content'][0]['text']);
        $this->assertSame(['conversations' => []], json_decode($result['content'][0]['text'], true));
    }

    private function givenConversation(): Conversation {
        return new Conversation(new ModuleRoomStub('abcd'), new ModuleParticipantStub());
    }

    /**
     * @param array{content:list<array{type:string, text:string}>, isError?:bool} $result Result of a tool call
     * @return array<string, mixed> The payload of a successful result
     */
    private function payloadOf(array $result): array {
        $this->assertArrayNotHasKey('isError', $result);

        return json_decode($result['content'][0]['text'], true);
    }
}

final class ModuleRoomStub {
    public function __construct(private string $token) {}

    public function getToken(): string {
        return $this->token;
    }
}

final class ModuleParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}
