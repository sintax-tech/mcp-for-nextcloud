<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ApprovalException;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\DraftApproval;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
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
    private UserConversationResolver&MockObject $userConversations;
    private GroupCreator&MockObject $groups;
    private LoggerInterface&MockObject $logger;
    private TalkModule $module;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = $this->createMock(ConversationReader::class);
        $this->resolver = $this->createMock(ConversationResolver::class);
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->draftApproval = $this->createMock(DraftApproval::class);
        $this->userConversations = $this->createMock(UserConversationResolver::class);
        $this->groups = $this->createMock(GroupCreator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->module = new TalkModule(
            $this->createMock(TalkServices::class),
            $this->reader,
            $this->resolver,
            $this->writer,
            $this->sharer,
            $this->draftApproval,
            $this->userConversations,
            $this->groups,
            $this->logger,
        );
    }

    public function testTheModuleAdvertisesExactlyTheToolsOfTheContract(): void {
        $this->assertSame(
            [
                'talk_list_conversations' => 'read',
                'talk_read_messages' => 'read',
                'talk_reply' => 'reply',
                'talk_attach_file' => 'attach',
                'talk_quote_file' => 'quote',
                'talk_message_user' => 'reply',
                'talk_send_batch' => 'reply',
                'talk_create_group' => 'create',
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
        $this->assertSame(['user', 'message'], $schemas['talk_message_user']['required']);
        $this->assertSame(['name'], $schemas['talk_create_group']['required']);
        $this->assertSame(GroupCreator::MAX_PARTICIPANTS, $schemas['talk_create_group']['properties']['participants']['maxItems']);
        // The account id goes through the same bound as a token, and the text through the same bound as a message.
        $this->assertSame(64, $schemas['talk_message_user']['properties']['user']['maxLength']);
        $this->assertSame(ConversationWriter::MAX_MESSAGE_LENGTH, $schemas['talk_message_user']['properties']['message']['maxLength']);
        // No conversation_token: the room is the product's to find, and it is not created to show a draft.
        $this->assertArrayNotHasKey('conversation_token', $schemas['talk_message_user']['properties']);
        $this->assertArrayNotHasKey('message', $schemas['talk_attach_file']['required']);
        $this->assertArrayNotHasKey('message', $schemas['talk_quote_file']['required']);
        // confirm is what turns a draft into a send, so it is a boolean the caller may omit: an absent one is
        // the draft, never an error, and only an explicit true publishes.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
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

        $this->draftApproval->expects($this->once())
            ->method('approveReply')
            ->with($conversation, 'alice', 'APR-1', 'bom dia', 42);

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            [
                'conversation_token' => 'abcd',
                'message' => 'bom dia',
                'reply_to' => 42,
                'confirm' => true,
                'approval_id' => 'APR-1',
            ],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'abcd', 'messageId' => 55], $this->payloadOf($result));
    }

    public function testReplyWithoutAQuotedIdPassesNoParent(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($this->anything(), 'alice', 'bom dia', null);

        $this->draftApproval->expects($this->once())
            ->method('approveReply')
            ->with($this->anything(), 'alice', 'APR-1', 'bom dia', null);

        $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'confirm' => true, 'approval_id' => 'APR-1'],
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

        $this->draftApproval->expects($this->once())
            ->method('approveAttach')
            ->with($this->anything(), 'alice', 'APR-2', 'relatorio.pdf', null);

        $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf', 'confirm' => true, 'approval_id' => 'APR-2'],
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
        $this->draftApproval->expects($this->once())
            ->method('approveQuote')
            ->with($conversation, 'alice', 'APR-3', 77, 'a figura');

        $result = $this->module->call(
            TalkModule::TOOL_QUOTE,
            [
                'conversation_token' => 'abcd',
                'attachment_id' => 77,
                'message' => 'a figura',
                'confirm' => true,
                'approval_id' => 'APR-3',
            ],
            'alice',
        );

        $this->assertSame(77, $this->payloadOf($result)['attachmentId']);
    }

    public function testEveryWritingToolAsksTheAgentForTheApprovalInItsDescription(): void {
        $descriptions = array_column($this->module->definitions(), 'description', 'name');

        // The description is the only thing the agent reads before it decides to call the tool, so the rule
        // lives in the sentence itself and not only in this test.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertStringContainsString(
                'Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário'
                    . ' e obter aprovação explícita; só então repetir com confirm: true e o approval_id da prévia.',
                $descriptions[$name],
                $name,
            );
        }

        foreach (['talk_list_conversations', 'talk_read_messages'] as $name) {
            $this->assertStringNotContainsString('confirm: true', $descriptions[$name], $name);
        }
    }

    public function testADirectMessageIsResolvedFromTheAccountAndSentThroughTheProductCall(): void {
        $conversation = $this->givenConversation('zzzz');
        $this->userConversations->expects($this->once())
            ->method('conversation')
            ->with('alice', 'bob')
            ->willReturn($conversation);
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', 'oi', null)
            ->willReturn(['conversation_token' => 'zzzz', 'messageId' => 91]);
        $this->draftApproval->expects($this->once())
            ->method('approveDirectMessage')
            ->with('alice', 'APR-9', 'bob', 'oi');

        $result = $this->module->call(
            TalkModule::TOOL_MESSAGE_USER,
            ['user' => 'bob', 'message' => 'oi', 'confirm' => true, 'approval_id' => 'APR-9'],
            'alice',
        );

        // The room comes and goes inside the call: only the target and the id of the sent message are worth showing.
        $this->assertSame(
            ['conversation_token' => 'zzzz', 'messageId' => 91, 'user' => ['id' => 'bob', 'displayName' => 'Comercial']],
            $this->payloadOf($result),
        );
    }

    public function testAGroupIsDraftedWithTheNameAndTheGuestsAndCreatedOnlyWhenApproved(): void {
        $contacts = [new DirectContact('bob', 'Bob Souza'), new DirectContact('carol', 'Carol Lima')];
        $this->userConversations->method('contacts')->willReturn($contacts);
        $this->draftApproval->expects($this->once())
            ->method('group')
            ->with('alice', 'Projeto X', $contacts)
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_create_group']);
        // A preview that created the room would leave a group nobody approved in the user's list.
        $this->groups->expects($this->never())->method('create');

        $draft = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob', 'carol']],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_create_group'], $this->payloadOf($draft));
    }

    public function testTheApprovedGroupIsCreatedWithTheSameNameAndGuests(): void {
        $this->userConversations->method('contacts')->willReturn([]);
        $this->draftApproval->expects($this->once())
            ->method('approveGroup')
            ->with('alice', 'APR-3', 'Projeto X', ['bob']);
        $this->groups->expects($this->once())
            ->method('create')
            ->with('alice', 'Projeto X', ['bob'])
            ->willReturn(['conversation_token' => 'wxyz', 'name' => 'Projeto X', 'participants' => []]);

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob'], 'confirm' => true, 'approval_id' => 'APR-3'],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'wxyz', 'name' => 'Projeto X', 'participants' => []], $this->payloadOf($result));
    }

    public function testAGroupWithoutANameIsAClientMistakeBeforeAnythingIsLookedUp(): void {
        $this->userConversations->expects($this->never())->method('contacts');
        $this->groups->expects($this->never())->method('create');

        try {
            $this->module->call(TalkModule::TOOL_CREATE_GROUP, ['name' => '   '], 'alice');
            $this->fail('a group without a name was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::INVALID_GROUP_NAME, $e->getMessage());
        }
    }

    public function testAReusedGroupDraftCreatesNothing(): void {
        // The approval is spent by the first confirmed call, so a second one with the same id is refused and the
        // group is not created twice.
        $this->draftApproval->method('approveGroup')
            ->willThrowException(new ApprovalException(Messages::APPROVAL_INVALID));
        $this->groups->expects($this->never())->method('create');

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob'], 'confirm' => true, 'approval_id' => 'APR-3'],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::APPROVAL_INVALID, $result['content'][0]['text']);
    }

    public function testAGroupWithSomeGuestsRefusedIsASuccessThatNamesThem(): void {
        $created = [
            'conversation_token' => 'wxyz',
            'name' => 'Projeto X',
            'participants' => [['id' => 'bob', 'displayName' => 'Bob Souza']],
            'invitations_failed' => [['id' => 'carol', 'displayName' => 'Carol Lima', 'error' => Messages::INVITATION_FAILED]],
        ];
        $this->groups->method('create')->willReturn($created);

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob', 'carol'], 'confirm' => true, 'approval_id' => 'APR-3'],
            'alice',
        );

        // The group exists: an error here would push the agent to create it again.
        $this->assertSame($created, $this->payloadOf($result));
    }

    public function testAGuestOutOfReachInTheDraftIsNamedInTheRefusal(): void {
        $this->userConversations->expects($this->once())
            ->method('contacts')
            ->with('alice', ['bob', 'ghost'], true)
            ->willThrowException(new ConversationAccessException(sprintf(Messages::PARTICIPANT_NOT_REACHABLE, 'ghost')));
        $this->draftApproval->expects($this->never())->method('group');

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob', 'ghost']],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(sprintf(Messages::PARTICIPANT_NOT_REACHABLE, 'ghost'), $result['content'][0]['text']);
    }

    public function testAGuestThatIsNotAnAccountIdIsAClientMistake(): void {
        $this->userConversations->expects($this->never())->method('contacts');

        try {
            $this->module->call(TalkModule::TOOL_CREATE_GROUP, ['name' => 'Projeto X', 'participants' => [42]], 'alice');
            $this->fail('a participant that is not an account id was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::INVALID_USER, $e->getMessage());
        }
    }

    public function testABatchIsApprovedAsAWholeAndTheWriterReportsEachItem(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->draftApproval->expects($this->once())
            ->method('approveBatch')
            ->with($conversation, 'alice', 'APR-2', [
                ['message' => 'primeiro', 'replyTo' => null],
                ['message' => 'segundo', 'replyTo' => 42],
            ]);
        $this->writer->expects($this->once())
            ->method('replyMany')
            ->with($conversation, 'alice', [
                ['message' => 'primeiro', 'replyTo' => null],
                ['message' => 'segundo', 'replyTo' => 42],
            ])
            ->willReturn([
                'conversation_token' => 'abcd',
                'sent' => [['index' => 0, 'messageId' => 61]],
                'failed' => [['index' => 1, 'error' => Messages::MESSAGE_NOT_SENT]],
            ]);
        $this->writer->expects($this->never())->method('reply');

        $result = $this->module->call(
            TalkModule::TOOL_SEND_BATCH,
            [
                'conversation_token' => 'abcd',
                'messages' => [
                    ['message' => 'primeiro'],
                    ['message' => 'segundo', 'reply_to' => 42],
                ],
                'confirm' => true,
                'approval_id' => 'APR-2',
            ],
            'alice',
        );

        // Part of the batch is in the room: the answer has to say which part, so the caller does not repeat it.
        $this->assertSame(
            [
                'conversation_token' => 'abcd',
                'sent' => [['index' => 0, 'messageId' => 61]],
                'failed' => [['index' => 1, 'error' => Messages::MESSAGE_NOT_SENT]],
            ],
            $this->payloadOf($result),
        );
    }

    public function testABatchWithoutConfirmationStopsAtTheDraftAndSendsNothing(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->draftApproval->expects($this->once())
            ->method('batch')
            ->with($conversation, 'alice', [['message' => 'primeiro', 'replyTo' => null]])
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_send_batch']);
        $this->writer->expects($this->never())->method('replyMany');

        $result = $this->module->call(
            TalkModule::TOOL_SEND_BATCH,
            ['conversation_token' => 'abcd', 'messages' => [['message' => 'primeiro']]],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_send_batch'], $this->payloadOf($result));
    }

    public function testAMalformedBatchIsRefusedBeforeTheConversationIsResolved(): void {
        foreach ([[], 'primeiro', [['reply_to' => 1]]] as $messages) {
            $this->resolver->expects($this->never())->method('resolveForWriting');
            try {
                $this->module->call(
                    TalkModule::TOOL_SEND_BATCH,
                    ['conversation_token' => 'abcd', 'messages' => $messages],
                    'alice',
                );
                $this->fail('accepted ' . json_encode($messages));
            } catch (InvalidArgumentException $e) {
                $this->assertContains($e->getMessage(), [Messages::EMPTY_BATCH, Messages::EMPTY_MESSAGE]);
            }
        }
    }

    public function testADirectMessageWithoutConfirmationStopsAtTheDraftAndCreatesNoRoom(): void {
        $contact = new DirectContact('bob', 'Bob Souza');
        $this->userConversations->expects($this->once())
            ->method('target')
            ->with('alice', 'bob')
            ->willReturn($contact);
        $this->draftApproval->expects($this->once())
            ->method('directMessage')
            ->with($contact, 'alice', 'oi')
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_message_user']);
        // A preview that opened a conversation would leave an empty room in the user's Talk list.
        $this->userConversations->expects($this->never())->method('conversation');
        $this->writer->expects($this->never())->method('reply');

        $result = $this->module->call(
            TalkModule::TOOL_MESSAGE_USER,
            ['user' => 'bob', 'message' => 'oi'],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_message_user'], $this->payloadOf($result));
        $this->assertArrayNotHasKey('isError', $result);
    }

    public function testADirectMessageIsApprovedBeforeTheRoomExists(): void {
        $this->userConversations->method('conversation')->willReturn($this->givenConversation('zzzz'));
        $this->writer->method('reply')->willReturn(['conversation_token' => 'zzzz', 'messageId' => 91]);
        $calls = [];
        $this->draftApproval->method('approveDirectMessage')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'approved';
            },
        );
        $this->userConversations->method('conversation')->willReturnCallback(
            function () use (&$calls): Conversation {
                $calls[] = 'room';
                return $this->givenConversation('zzzz');
            },
        );

        $this->module->call(
            TalkModule::TOOL_MESSAGE_USER,
            ['user' => 'bob', 'message' => 'oi', 'confirm' => true, 'approval_id' => 'APR-9'],
            'alice',
        );

        // An approval that is spent after the room exists would leave a conversation nobody approved.
        $this->assertSame(['approved', 'room'], $calls);
    }

    public function testADirectMessageWithoutATargetIsAClientMistakeBeforeAnythingIsLookedUp(): void {
        $this->userConversations->expects($this->never())->method('target');
        $this->userConversations->expects($this->never())->method('conversation');

        try {
            $this->module->call(TalkModule::TOOL_MESSAGE_USER, ['message' => 'oi'], 'alice');
            $this->fail('a message without a target was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::INVALID_USER, $e->getMessage());
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

    public function testTheDraftInstructionTellsTheAgentWhichIdToSendBack(): void {
        $descriptions = array_column($this->module->definitions(), 'description', 'name');

        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertStringContainsString(
                'Antes de enviar, o agente DEVE chamar a tool sem confirm, mostrar o rascunho devolvido ao usuário',
                $descriptions[$name],
                $name,
            );
        }
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

    public function testAConfirmedCallWithoutAnApprovalIdPublishesNothing(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->draftApproval->method('approveReply')
            ->willThrowException(new ApprovalException(Messages::APPROVAL_MISSING));
        // confirm: true without a draft behind it is the case the review pointed at: nothing may go out.
        $this->writer->expects($this->never())->method('reply');
        $this->sharer->expects($this->never())->method('attach');
        $this->writer->expects($this->never())->method('quoteAttachment');

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'bom dia', 'confirm' => true],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::APPROVAL_MISSING, $result['content'][0]['text']);
    }

    public function testAnApprovalRefusedByTheDraftBuilderKeepsTheWriteFromHappening(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->draftApproval->method('approveQuote')
            ->willThrowException(new ApprovalException(Messages::APPROVAL_INVALID));
        $this->writer->expects($this->never())->method('quoteAttachment');

        $result = $this->module->call(
            TalkModule::TOOL_QUOTE,
            ['conversation_token' => 'abcd', 'attachment_id' => 77, 'confirm' => true, 'approval_id' => 'APR-3'],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::APPROVAL_INVALID, $result['content'][0]['text']);
    }

    public function testTheApprovalIsCheckedBeforeTheFileIsShared(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->draftApproval->expects($this->once())
            ->method('approveAttach')
            ->with($this->anything(), 'alice', 'APR-2', 'relatorio.pdf', null)
            ->willThrowException(new ApprovalException(Messages::APPROVAL_INVALID));
        // Ordering, not just refusal: an invalid approval must not leave a share behind.
        $this->sharer->expects($this->never())->method('attach');

        $this->module->call(
            TalkModule::TOOL_ATTACH,
            ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf', 'confirm' => true, 'approval_id' => 'APR-2'],
            'alice',
        );
    }

    public function testADraftWithoutConfirmIsNeverCheckedAsAnApproval(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->draftApproval->expects($this->never())->method('approveReply');
        $this->draftApproval->method('reply')->willReturn(['requiresConfirmation' => true]);

        $this->module->call(TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi'], 'alice');
    }

    public function testACaptionLostAfterTheAttachmentIsNotAnErrorButIsAudited(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->sharer->method('attach')->willReturn([
            'conversation_token' => 'abcd',
            'attachmentId' => 77,
            'file' => ['path' => '/alice/files/relatorio.pdf', 'name' => 'relatorio.pdf', 'size' => 1],
            'caption' => 'legenda',
            'captionSent' => false,
            'message' => Messages::CAPTION_NOT_SENT,
        ]);
        // The card is in the room, so the result answers what happened and the log keeps the failure visible.
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->anything(), $this->callback(static fn (array $context): bool => $context['attachmentId'] === 77));

        $result = $this->module->call(
            TalkModule::TOOL_ATTACH,
            [
                'conversation_token' => 'abcd',
                'path' => 'relatorio.pdf',
                'message' => 'legenda',
                'confirm' => true,
                'approval_id' => 'APR-2',
            ],
            'alice',
        );

        $this->assertArrayNotHasKey('isError', $result);
        $this->assertFalse($this->payloadOf($result)['captionSent']);
    }

    public function testTheSchemasTellTheAgentWhichIdTheApprovalIs(): void {
        $schemas = array_column($this->module->definitions(), 'inputSchema', 'name');

        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertSame(
                ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                $schemas[$name]['properties']['approval_id'],
                $name,
            );
            // Optional on its own: only the draft call needs it absent.
            $this->assertArrayNotHasKey('approval_id', $schemas[$name]['required'], $name);
        }
    }

    public function testTheDescriptionTellsTheAgentHowToApproveAndWhoHasToApprove(): void {
        $descriptions = array_column($this->module->definitions(), 'description', 'name');

        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertStringContainsString('approval_id da prévia', $descriptions[$name], $name);
            // The server can prove it showed the draft; it cannot prove a human said yes. Say so.
            $this->assertStringContainsString(
                'a aprovação em si depende de o agente mostrar o rascunho e de o usuário confirmar no cliente',
                $descriptions[$name],
                $name,
            );
        }
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

    private function givenConversation(string $token = 'abcd'): Conversation {
        return new Conversation(new ModuleRoomStub($token), new ModuleParticipantStub());
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

    public function getDisplayName(string $userId): string {
        return 'Comercial';
    }
}

final class ModuleParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}
