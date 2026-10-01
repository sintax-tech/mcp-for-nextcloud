<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\FileAccessException;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\ReferenceLinker;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use OCA\Mcp\Tools\Talk\WritePreview;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tools\WriteGate;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TalkModuleTest extends TestCase {
    private ConversationReader&MockObject $reader;
    private ConversationResolver&MockObject $resolver;
    private ConversationWriter&MockObject $writer;
    private FileSharer&MockObject $sharer;
    private WritePreview&MockObject $preview;
    private UserConversationResolver&MockObject $userConversations;
    private GroupCreator&MockObject $groups;
    private ReferenceLinker&MockObject $references;
    private LoggerInterface&MockObject $logger;
    private TalkModule $module;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = $this->createMock(ConversationReader::class);
        $this->resolver = $this->createMock(ConversationResolver::class);
        $this->writer = $this->createMock(ConversationWriter::class);
        $this->sharer = $this->createMock(FileSharer::class);
        $this->preview = $this->createMock(WritePreview::class);
        $this->userConversations = $this->createMock(UserConversationResolver::class);
        $this->groups = $this->createMock(GroupCreator::class);
        $this->references = $this->createMock(ReferenceLinker::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->module = new TalkModule(
            $this->createMock(TalkServices::class),
            $this->reader,
            $this->resolver,
            $this->writer,
            $this->sharer,
            $this->preview,
            $this->userConversations,
            $this->groups,
            $this->references,
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
        $schemas = array_column(array_map([WriteGate::class, 'publish'], $this->module->definitions()), 'inputSchema', 'name');

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
        // confirm is what turns a draft into a send, published by the registry on every write as a boolean the
        // caller may omit: an absent one is the draft, never an error, and only an explicit true publishes.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertSame(WriteGate::confirmProperty(), $schemas[$name]['properties']['confirm'], $name);
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
            [
                'conversation_token' => 'abcd',
                'message' => 'bom dia',
                'reply_to' => 42,
                'confirm' => true
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
            [
                'conversation_token' => 'abcd',
                'attachment_id' => 77,
                'message' => 'a figura',
                'confirm' => true
            ],
            'alice',
        );

        $this->assertSame(77, $this->payloadOf($result)['attachmentId']);
    }

    public function testEveryWritingToolTellsTheAgentToPlanFirstAndConfirmAfterwards(): void {
        $descriptions = array_column($this->module->definitions(), 'description', 'name');

        // The description is the only thing the agent reads before it decides to call the tool, so the rule
        // lives in the sentence itself and not only in this test.
        foreach (['talk_reply', 'talk_attach_file', 'talk_quote_file', 'talk_message_user', 'talk_send_batch', 'talk_create_group'] as $name) {
            $this->assertStringContainsString(
                'Before sending, the agent MUST call the tool without confirm,',
                $descriptions[$name],
                $name,
            );
            $this->assertStringContainsString('only after an explicit yes repeat the', $descriptions[$name], $name);
            $this->assertStringContainsString('confirm: true and the same arguments', $descriptions[$name], $name);
            // Nothing may invite the agent to carry an id back: there is none to carry.
            $this->assertStringNotContainsString('approval_id', $descriptions[$name], $name);
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

        $result = $this->module->call(
            TalkModule::TOOL_MESSAGE_USER,
            ['user' => 'bob', 'message' => 'oi', 'confirm' => true],
            'alice',
        );

        // The room comes and goes inside the call: only the target and the id of the sent message are worth showing.
        $this->assertSame(
            ['conversation_token' => 'zzzz', 'messageId' => 91, 'user' => ['id' => 'bob', 'displayName' => 'Comercial']],
            $this->payloadOf($result),
        );
    }

    public function testAGroupIsPreviewedWithTheNameAndTheGuestsAndCreatedOnlyWhenConfirmed(): void {
        $contacts = [new DirectContact('bob', 'Bob Souza'), new DirectContact('carol', 'Carol Lima')];
        $this->userConversations->expects($this->once())
            ->method('contacts')
            ->with('alice', ['bob', 'carol'], true)
            ->willReturn($contacts);
        $this->preview->expects($this->once())
            ->method('group')
            ->with('alice', 'Projeto X', $contacts)
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_create_group']);
        // A preview that created the room would leave a group nobody asked for in the user's list.
        $this->groups->expects($this->never())->method('create');

        $plan = $this->module->preview(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob', 'carol']],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_create_group'], $plan);
    }

    public function testTheApprovedGroupIsCreatedWithTheSameNameAndGuests(): void {
        $this->userConversations->method('contacts')->willReturn([]);
        $this->groups->expects($this->once())
            ->method('create')
            ->with('alice', 'Projeto X', ['bob'])
            ->willReturn(['conversation_token' => 'wxyz', 'name' => 'Projeto X', 'participants' => []]);

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob'], 'confirm' => true],
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
            $this->assertSame(Messages::invalidGroupName(), $e->getMessage());
        }
    }

    public function testAReferenceIsPreviewedAsTheFinalTextWithTheItemShown(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $item = ['type' => 'deck_card', 'title' => 'Proposta ACME', 'url' => 'https://cloud.example/apps/deck/board/4/card/7'];
        $this->references->expects($this->once())
            ->method('resolve')
            ->with('alice', ['type' => 'deck_card', 'card_id' => 7])
            ->willReturn($item);
        // The plan is of the text that will be sent, link included: the user sees what the room will read.
        $this->preview->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', "veja\n\nDeck card: Proposta ACME\nhttps://cloud.example/apps/deck/board/4/card/7", null)
            ->willReturn(['requiresConfirmation' => true]);
        $this->writer->expects($this->never())->method('reply');

        $plan = $this->module->preview(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'veja', 'reference' => ['type' => 'deck_card', 'card_id' => 7]],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'reference' => $item], $plan);
    }

    public function testAConfirmedReferenceIsCheckedAgainAndSendsThePlanText(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->references->expects($this->once())
            ->method('resolve')
            ->willReturn(['type' => 'calendar_event', 'title' => 'Reunião', 'url' => 'https://cloud.example/apps/calendar/edit/x']);
        $text = "pauta\n\nCalendar event: Reunião\nhttps://cloud.example/apps/calendar/edit/x";
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', $text, null)
            ->willReturn(['conversation_token' => 'abcd', 'messageId' => 12]);

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            [
                'conversation_token' => 'abcd',
                'message' => 'pauta',
                'reference' => ['type' => 'calendar_event', 'calendar' => '/remote.php/dav/calendars/alice/pessoal/', 'uid' => 'ev-1'],
                'confirm' => true
            ],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'abcd', 'messageId' => 12], $this->payloadOf($result));
    }

    public function testAReferenceOutOfReachSendsNothingAndShowsNoTitle(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->references->method('resolve')
            ->willThrowException(new ConversationAccessException(Messages::referenceNotFound()));
        $this->preview->expects($this->never())->method('reply');
        $this->writer->expects($this->never())->method('reply');

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'veja', 'reference' => ['type' => 'deck_card', 'card_id' => 7], 'confirm' => true],
            'alice',
        );

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::referenceNotFound(), $result['content'][0]['text']);
    }

    public function testAReplyWithoutReferenceNeverAsksForOne(): void {
        $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
        $this->references->expects($this->never())->method('resolve');
        $this->preview->method('reply')->willReturn(['requiresConfirmation' => true]);

        $result = $this->module->call(TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi'], 'alice');

        $this->assertArrayNotHasKey('reference', $this->payloadOf($result));
    }

    public function testTheReferenceSchemaNamesBothKindsAndNothingElse(): void {
        $reference = array_column($this->module->definitions(), 'inputSchema', 'name')['talk_reply']['properties']['reference'];

        $this->assertSame(['deck_card', 'calendar_event'], $reference['properties']['type']['enum']);
        $this->assertSame(['type'], $reference['required']);
        $this->assertFalse($reference['additionalProperties']);
    }

    public function testAGroupWithSomeGuestsRefusedIsASuccessThatNamesThem(): void {
        $created = [
            'conversation_token' => 'wxyz',
            'name' => 'Projeto X',
            'participants' => [['id' => 'bob', 'displayName' => 'Bob Souza']],
            'invitations_failed' => [['id' => 'carol', 'displayName' => 'Carol Lima', 'error' => Messages::invitationFailed()]],
        ];
        $this->groups->method('create')->willReturn($created);

        $result = $this->module->call(
            TalkModule::TOOL_CREATE_GROUP,
            ['name' => 'Projeto X', 'participants' => ['bob', 'carol'], 'confirm' => true],
            'alice',
        );

        // The group exists: an error here would push the agent to create it again.
        $this->assertSame($created, $this->payloadOf($result));
    }

    public function testAGuestOutOfReachInTheDraftIsNamedInTheRefusal(): void {
        $this->userConversations->expects($this->once())
            ->method('contacts')
            ->with('alice', ['bob', 'ghost'], true)
            ->willThrowException(new ConversationAccessException(Messages::participantNotReachable('ghost')));
        $this->preview->expects($this->never())->method('group');

        try {
            $this->module->preview(
                TalkModule::TOOL_CREATE_GROUP,
                ['name' => 'Projeto X', 'participants' => ['bob', 'ghost']],
                'alice',
            );
            $this->fail('a guest out of reach was planned');
        } catch (ToolFailure $e) {
            // The registry turns this into a normal error result with the same message.
            $this->assertSame(Messages::participantNotReachable('ghost'), $e->getMessage());
        }
    }

    public function testAGuestThatIsNotAnAccountIdIsAClientMistake(): void {
        $this->userConversations->expects($this->never())->method('contacts');

        try {
            $this->module->call(TalkModule::TOOL_CREATE_GROUP, ['name' => 'Projeto X', 'participants' => [42]], 'alice');
            $this->fail('a participant that is not an account id was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::invalidUser(), $e->getMessage());
        }
    }

    /**
     * The whole confirmation contract in one place: no confirm and an explicit false both stop at the plan,
     * confirm: true publishes, and nothing the caller can pass takes the place of an approval id.
     */
    public function testTheConfirmationIsOnlyTheConfirmBooleanAndNothingIsKept(): void {
        $writes = [
            [TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi']],
            [TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf']],
            [TalkModule::TOOL_QUOTE, ['conversation_token' => 'abcd', 'attachment_id' => 77]],
            [TalkModule::TOOL_MESSAGE_USER, ['user' => 'bob', 'message' => 'oi']],
            [TalkModule::TOOL_SEND_BATCH, ['conversation_token' => 'abcd', 'messages' => [['message' => 'oi']]]],
            [TalkModule::TOOL_CREATE_GROUP, ['name' => 'Projeto X']],
        ];

        foreach ($writes as [$name, $arguments]) {
            $this->resolver->method('resolveForWriting')->willReturn($this->givenConversation());
            $this->userConversations->method('target')->willReturn(new DirectContact('bob', 'Bob Souza'));
            $this->userConversations->method('contacts')->willReturn([]);
            $this->preview->method('reply')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_reply']);
            $this->preview->method('attach')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_attach_file']);
            $this->preview->method('quote')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_quote_file']);
            $this->preview->method('directMessage')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_message_user']);
            $this->preview->method('batch')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_send_batch']);
            $this->preview->method('group')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_create_group']);

            // Nothing in Talk is reached, whichever way the caller leaves confirm.
            $this->writer->expects($this->never())->method('reply');
            $this->writer->expects($this->never())->method('replyMany');
            $this->writer->expects($this->never())->method('quoteAttachment');
            $this->sharer->expects($this->never())->method('attach');
            $this->groups->expects($this->never())->method('create');

            foreach ([[], ['confirm' => false]] as $confirm) {
                $result = $this->registry()->call($name, $arguments + $confirm, 'alice');
                // A plan is an answer, not a failure: the agent has to be able to show it and ask.
                $this->assertArrayNotHasKey('isError', $result, $name);
                $this->assertTrue($this->payloadOf($result)['requiresConfirmation'], $name);
            }
        }
    }

    /** With confirm: true the same arguments publish, and no id is needed to get there. */
    public function testConfirmAloneIsEnoughToPublish(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->preview->expects($this->never())->method('reply');
        $this->writer->expects($this->once())
            ->method('reply')
            ->with($conversation, 'alice', 'oi', null)
            ->willReturn(['conversation_token' => 'abcd', 'messageId' => 55]);

        $result = $this->module->call(
            TalkModule::TOOL_REPLY,
            ['conversation_token' => 'abcd', 'message' => 'oi', 'confirm' => true],
            'alice',
        );

        $this->assertSame(['conversation_token' => 'abcd', 'messageId' => 55], $this->payloadOf($result));
    }

    /** A batch over the limit is refused while the plan is being built, before the conversation is even read. */
    public function testABatchOverTheLimitIsRefusedBeforeThePlanIsBuilt(): void {
        $this->resolver->expects($this->never())->method('resolveForWriting');
        $this->preview->expects($this->never())->method('batch');
        $this->writer->expects($this->never())->method('replyMany');

        try {
            $this->module->call(
                TalkModule::TOOL_SEND_BATCH,
                [
                    'conversation_token' => 'abcd',
                    'messages' => array_fill(0, WritePreview::MAX_BATCH + 1, ['message' => 'oi']),
                ],
                'alice',
            );
            $this->fail('a batch over the limit was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::tooManyMessages(WritePreview::MAX_BATCH), $e->getMessage());
        }
    }

    /**
     * The seam a central gate uses: preview() answers with the plan of a writing tool without publishing, so a
     * registry-level lock does not have to describe the write a second time.
     */
    public function testPreviewAnswersWithThePlanWithoutExecutingAnything(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->userConversations->method('target')->willReturn(new DirectContact('bob', 'Bob Souza'));
        $this->userConversations->method('contacts')->willReturn([new DirectContact('bob', 'Bob Souza')]);
        $this->preview->method('reply')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_reply']);
        $this->preview->method('attach')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_attach_file']);
        $this->preview->method('quote')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_quote_file']);
        $this->preview->method('directMessage')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_message_user']);
        $this->preview->method('batch')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_send_batch']);
        $this->preview->method('group')->willReturn(['requiresConfirmation' => true, 'action' => 'talk_create_group']);

        $this->writer->expects($this->never())->method('reply');
        $this->writer->expects($this->never())->method('replyMany');
        $this->writer->expects($this->never())->method('quoteAttachment');
        $this->sharer->expects($this->never())->method('attach');
        $this->groups->expects($this->never())->method('create');

        foreach (
            [
                [TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi']],
                [TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd', 'path' => 'relatorio.pdf']],
                [TalkModule::TOOL_QUOTE, ['conversation_token' => 'abcd', 'attachment_id' => 77]],
                [TalkModule::TOOL_MESSAGE_USER, ['user' => 'bob', 'message' => 'oi']],
                [TalkModule::TOOL_SEND_BATCH, ['conversation_token' => 'abcd', 'messages' => [['message' => 'oi']]]],
                [TalkModule::TOOL_CREATE_GROUP, ['name' => 'Projeto X']],
            ] as [$name, $arguments]
        ) {
            $plan = $this->module->preview($name, $arguments, 'alice');
            $this->assertTrue($plan['requiresConfirmation'], $name);
            $this->assertSame($name, $plan['action']);
            $this->assertArrayNotHasKey('approvalId', $plan, $name);
        }
    }

    /**
     * The module owns no text of its own: it hands the plan of every writing tool to the renderer of the module,
     * which is the one that knows how a person reads a conversation, a file name and a message.
     */
    public function testTheModuleRendersEveryWritingPlanAndOnlyThose(): void {
        $this->assertInstanceOf(RendersPlans::class, $this->module);

        $this->assertStringContainsString(
            'Comercial',
            (string)$this->module->renderPlan(TalkModule::TOOL_REPLY, [
                'conversation' => ['displayName' => 'Comercial'],
                'draft' => ['message' => 'bom dia'],
            ]),
        );
        $this->assertStringContainsString(
            'Bob Souza',
            (string)$this->module->renderPlan(TalkModule::TOOL_MESSAGE_USER, [
                'target' => ['displayName' => 'Bob Souza'],
                'draft' => ['message' => 'oi'],
            ]),
        );

        // A plan this module cannot read, and anything that is not a write, fall back to the generic body.
        $this->assertNull($this->module->renderPlan(TalkModule::TOOL_REPLY, []));
        $this->assertNull($this->module->renderPlan(TalkModule::TOOL_LIST, ['requiresConfirmation' => true]));
        $this->assertNull($this->module->renderPlan(TalkModule::TOOL_READ, []));
    }

    /** A read tool has no plan to build: preview() says so instead of inventing one. */
    public function testPreviewRefusesAReadToolAndKnowsWhichToolsWrite(): void {
        $this->assertFalse(TalkModule::writesSomething(TalkModule::TOOL_LIST));
        $this->assertFalse(TalkModule::writesSomething(TalkModule::TOOL_READ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::unknownTool());
        $this->module->preview(TalkModule::TOOL_READ, ['conversation_token' => 'abcd'], 'alice');
    }

    /** Every writing tool is exactly the set the central lock has to hold back. */
    public function testEveryWritingToolIsReportedAsWriting(): void {
        foreach ($this->module->definitions() as $definition) {
            $expected = $definition['operation'] !== 'read';
            $this->assertSame($expected, TalkModule::writesSomething($definition['name']), $definition['name']);
        }
    }

    public function testABatchIsConfirmedAsAWholeAndTheWriterReportsEachItem(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->writer->expects($this->once())
            ->method('replyMany')
            ->with($conversation, 'alice', [
                ['message' => 'primeiro', 'replyTo' => null],
                ['message' => 'segundo', 'replyTo' => 42],
            ])
            ->willReturn([
                'conversation_token' => 'abcd',
                'sent' => [['index' => 0, 'messageId' => 61]],
                'failed' => [['index' => 1, 'error' => Messages::messageNotSent()]],
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
                'confirm' => true
            ],
            'alice',
        );

        // Part of the batch is in the room: the answer has to say which part, so the caller does not repeat it.
        $this->assertSame(
            [
                'conversation_token' => 'abcd',
                'sent' => [['index' => 0, 'messageId' => 61]],
                'failed' => [['index' => 1, 'error' => Messages::messageNotSent()]],
            ],
            $this->payloadOf($result),
        );
    }

    public function testABatchWithoutConfirmationStopsAtThePlanAndSendsNothing(): void {
        $conversation = $this->givenConversation();
        $this->resolver->method('resolveForWriting')->willReturn($conversation);
        $this->preview->expects($this->once())
            ->method('batch')
            ->with($conversation, 'alice', [['message' => 'primeiro', 'replyTo' => null]])
            ->willReturn(['requiresConfirmation' => true, 'action' => 'talk_send_batch']);
        $this->writer->expects($this->never())->method('replyMany');

        $plan = $this->module->preview(
            TalkModule::TOOL_SEND_BATCH,
            ['conversation_token' => 'abcd', 'messages' => [['message' => 'primeiro']]],
            'alice',
        );

        $this->assertSame(['requiresConfirmation' => true, 'action' => 'talk_send_batch'], $plan);
    }

    public function testUnavailableSpreedIsAnsweredWithItsOwnMessage(): void {
        $this->resolver->method('resolveForWriting')
            ->willThrowException(new TalkUnavailableException(Messages::talkUnavailable()));

        $result = $this->module->call(TalkModule::TOOL_REPLY, ['conversation_token' => 'abcd', 'message' => 'oi'], 'alice');

        $this->assertTrue($result['isError']);
        $this->assertSame(Messages::talkUnavailable(), $result['content'][0]['text']);
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
        $this->assertSame(Messages::unexpected(), $result['content'][0]['text']);
    }

    public function testFileRefusalIsAnsweredWithoutReachingTheClient(): void {
        $this->resolver->method('resolveForWriting')->willThrowException(new FileAccessException(Messages::fileNotFound()));

        $result = $this->module->call(TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd', 'path' => 'a.pdf'], 'alice');

        $this->assertSame(Messages::fileNotFound(), $result['content'][0]['text']);
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
        $this->expectExceptionMessage(Messages::invalidIdentifier());
        $this->module->call(TalkModule::TOOL_QUOTE, ['conversation_token' => 'abcd'], 'alice');
    }

    public function testMissingPathIsAClientMistake(): void {
        $this->resolver->expects($this->never())->method('resolveForWriting');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidPath());
        $this->module->call(TalkModule::TOOL_ATTACH, ['conversation_token' => 'abcd'], 'alice');
    }

    public function testUnknownToolNameIsAClientMistake(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::unknownTool());
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

    /** The registry with this module and every Talk grant on: the one place that decides about confirm. */
    private function registry(): ToolRegistry {
        $policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore());
        foreach (GrantPolicy::CATALOG['talk'] as $operation) {
            $policy->setGrant('alice', 'talk', $operation, true);
        }
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturn($this->createMock(IUser::class));

        return new ToolRegistry([$this->module], $policy, $apps, $users, $this->createMock(LoggerInterface::class));
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
