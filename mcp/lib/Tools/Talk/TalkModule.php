<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolModule;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tools of the talk module, routed by name.
 *
 * The module holds no access rule of its own: the registry checks the grant and the app enablement, and every
 * resource decision belongs to ConversationResolver, UserFileResolver or the share lookup. What it does own is the
 * translation of a failure into an MCP result, keeping every user-facing string in Messages.
 *
 * Every writing tool stops at a plan until the user says yes: the registry answers a call without confirm: true
 * with {@see self::preview()} and writes nothing, and only a confirmed call reaches {@see self::call()}, which
 * revalidates and publishes. Nothing is persisted between the two calls, so the conversation is the client's
 * promise to show the plan and to report the answer. That plan is shown through {@see TalkPlanRenderer}, so the
 * person reads who the message goes to and what it says rather than the shape of the payload.
 */
class TalkModule implements ToolModule, PreviewsWrites, RendersPlans {
    public const TOOL_LIST = 'talk_list_conversations';
    public const TOOL_READ = 'talk_read_messages';
    public const TOOL_REPLY = 'talk_reply';
    public const TOOL_ATTACH = 'talk_attach_file';
    public const TOOL_QUOTE = 'talk_quote_file';
    public const TOOL_MESSAGE_USER = 'talk_message_user';
    public const TOOL_SEND_BATCH = 'talk_send_batch';
    public const TOOL_CREATE_GROUP = 'talk_create_group';

    private const MODULE = 'talk';
    private const APP = TalkServices::APP_ID;
    /** Public conversation token; Talk tokens are 30 characters at most, so this only bounds the payload. */
    private const TOKEN_SCHEMA = ['type' => 'string', 'minLength' => 1, 'maxLength' => 30];

    public function __construct(
        private TalkServices $talkServices,
        private ConversationReader $reader,
        private ConversationResolver $resolver,
        private ConversationWriter $writer,
        private FileSharer $sharer,
        private WritePreview $preview,
        private UserConversationResolver $userConversations,
        private GroupCreator $groups,
        private ReferenceLinker $references,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{name:string, description:string, inputSchema:array, module:string, operation:string, app:string}>
     */
    public function definitions(): array {
        return [
            [
                'name' => self::TOOL_LIST,
                'description' => Messages::TOOL_LIST_CONVERSATIONS,
                'inputSchema' => $this->emptySchema(),
                'module' => self::MODULE,
                'operation' => 'read',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_READ,
                'description' => Messages::TOOL_READ_MESSAGES,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_token' => self::TOKEN_SCHEMA,
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => ConversationReader::MAX_MESSAGES,
                            'default' => ConversationReader::DEFAULT_MESSAGES,
                        ],
                    ],
                    'required' => ['conversation_token'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'read',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_REPLY,
                'description' => Messages::TOOL_REPLY,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_token' => self::TOKEN_SCHEMA,
                        'message' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH,
                        ],
                        'reply_to' => ['type' => 'integer', 'minimum' => 1],
                        'reference' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => [
                                    'type' => 'string',
                                    'enum' => [ReferenceLinker::TYPE_DECK_CARD, ReferenceLinker::TYPE_CALENDAR_EVENT],
                                ],
                                'card_id' => ['type' => 'integer', 'minimum' => 1],
                                'board_id' => ['type' => 'integer', 'minimum' => 1],
                                'calendar' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => ReferenceLinker::MAX_CALENDAR_LENGTH,
                                ],
                                'uid' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => ReferenceLinker::MAX_UID_LENGTH,
                                ],
                            ],
                            'required' => ['type'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['conversation_token', 'message'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'reply',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_ATTACH,
                'description' => Messages::TOOL_ATTACH_FILE,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_token' => self::TOKEN_SCHEMA,
                        'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                        'message' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH,
                        ],
                    ],
                    'required' => ['conversation_token', 'path'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'attach',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_QUOTE,
                'description' => Messages::TOOL_QUOTE_FILE,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_token' => self::TOKEN_SCHEMA,
                        'attachment_id' => ['type' => 'integer', 'minimum' => 1],
                        'message' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => AttachmentMessage::MAX_CAPTION,
                        ],
                    ],
                    'required' => ['conversation_token', 'attachment_id'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'quote',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_MESSAGE_USER,
                'description' => Messages::TOOL_MESSAGE_USER,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'user' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => UserConversationResolver::MAX_TARGET_LENGTH,
                        ],
                        'message' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH,
                        ],
                    ],
                    'required' => ['user', 'message'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'reply',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_SEND_BATCH,
                'description' => Messages::TOOL_SEND_BATCH,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'conversation_token' => self::TOKEN_SCHEMA,
                        'messages' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => WritePreview::MAX_BATCH,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => [
                                        'type' => 'string',
                                        'minLength' => 1,
                                        'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH,
                                    ],
                                    'reply_to' => [
                                        'type' => 'integer',
                                        'minimum' => 1,
                                    ],
                                ],
                                'required' => ['message'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['conversation_token', 'messages'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'reply',
                'app' => self::APP,
            ],
            [
                'name' => self::TOOL_CREATE_GROUP,
                'description' => Messages::TOOL_CREATE_GROUP,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => GroupCreator::MAX_NAME_LENGTH,
                        ],
                        'participants' => [
                            'type' => 'array',
                            'maxItems' => GroupCreator::MAX_PARTICIPANTS,
                            'items' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => UserConversationResolver::MAX_TARGET_LENGTH,
                            ],
                        ],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'create',
                'app' => self::APP,
            ],
        ];
    }

    /**
     * Builds the plan of a writing tool without executing anything, for whoever enforces the confirmation.
     *
     * This is the only entry point a central gate needs: the plan comes from the same resolution the call does,
     * so a gate that refuses a write without confirm: true can show the very plan the confirmed call would act on
     * instead of a second, weaker description of it.
     *
     * @param string $name Name of a writing tool of this module
     * @param array<string, mixed> $arguments Validated arguments, without confirm
     * @param string $userId Authenticated user
     * @return array<string, mixed> The plan, ready to be shown to the user
     * @throws InvalidArgumentException For an unknown tool name or a malformed argument
     * @throws ToolFailure For a conversation, file or Talk failure, with the message the confirmed call would give
     */
    public function preview(string $name, array $arguments, string $userId): array {
        try {
            return match ($name) {
                self::TOOL_REPLY => $this->replyPreview($userId, $arguments),
                self::TOOL_ATTACH => $this->attachPreview($userId, $arguments),
                self::TOOL_QUOTE => $this->quotePreview($userId, $arguments),
                self::TOOL_MESSAGE_USER => $this->messageUserPreview($userId, $arguments),
                self::TOOL_SEND_BATCH => $this->sendBatchPreview($userId, $arguments),
                self::TOOL_CREATE_GROUP => $this->createGroupPreview($userId, $arguments),
                default => throw new InvalidArgumentException(Messages::unknownTool()),
            };
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The plan says what the confirmed call would say: a missing conversation or file is reported as
            // such, not as the registry's generic failure.
            $this->logger->error('Talk tool failed', [
                'app' => 'mcp',
                'tool' => $name,
                'exception' => $e::class,
            ]);

            throw new ToolFailure($this->messageFor($e));
        }
    }

    /**
     * The plan of this module's write tools in the words of the person approving it, so the confirmation shows a
     * conversation, a person and a text instead of a payload. A plan this module cannot name returns null and the
     * envelope falls back to its generic body; the module itself decides nothing about how a plan is presented.
     *
     * @param string $tool Tool name
     * @param array<string, mixed> $plan The plan returned by preview()
     * @return string|null Markdown body, or null when the plan is not one this module renders
     */
    public function renderPlan(string $tool, array $plan): ?string {
        return TalkPlanRenderer::render($tool, $plan);
    }

    /**
     * @param string $name Name of a writing tool of this module
     * @return bool Whether the tool publishes something, so a central gate can hold it back without confirm
     */
    public static function writesSomething(string $name): bool {
        return in_array($name, [
            self::TOOL_REPLY,
            self::TOOL_ATTACH,
            self::TOOL_QUOTE,
            self::TOOL_MESSAGE_USER,
            self::TOOL_SEND_BATCH,
            self::TOOL_CREATE_GROUP,
        ], true);
    }

    /**
     * Routes one tool call. Arguments arrive already validated against the schema, so only the rules a schema cannot
     * express are checked here, further down: a blank message, a malformed token or a path with a relative segment.
     *
     * @param string $name Tool name
     * @param array<string, mixed> $arguments Validated arguments
     * @param string $userId Authenticated user
     * @return array{content:list<array{type:string, text:string}>, isError?:bool}
     * @throws InvalidArgumentException For a malformed argument or an unknown tool name
     */
    public function call(string $name, array $arguments, string $userId): array {
        try {
            return match ($name) {
                self::TOOL_LIST => ToolResult::success([
                    'conversations' => $this->reader->listConversations($userId),
                ]),
                self::TOOL_READ => ToolResult::success([
                    'messages' => $this->reader->readMessages(
                        $userId,
                        $this->token($arguments),
                        $this->limit($arguments),
                    ),
                ]),
                self::TOOL_REPLY => ToolResult::success($this->replyCall($userId, $arguments)),
                self::TOOL_ATTACH => ToolResult::success($this->attachCall($userId, $arguments)),
                self::TOOL_QUOTE => ToolResult::success($this->quoteCall($userId, $arguments)),
                self::TOOL_MESSAGE_USER => ToolResult::success($this->messageUserCall($userId, $arguments)),
                self::TOOL_SEND_BATCH => ToolResult::success($this->sendBatchCall($userId, $arguments)),
                self::TOOL_CREATE_GROUP => ToolResult::success($this->createGroupCall($userId, $arguments)),
                default => throw new InvalidArgumentException(Messages::unknownTool()),
            };
        } catch (InvalidArgumentException $e) {
            // The registry turns this into -32602, and it is a client mistake, not an error worth logging.
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Talk tool failed', [
                'app' => 'mcp',
                'tool' => $name,
                'exception' => $e::class,
            ]);

            return ToolResult::error($this->messageFor($e));
        }
    }

    /**
     * A refusal already carries a message written for the user, so only the unexpected cases fall back to a
     * generic one. The exception message of a Talk failure is never shown.
     *
     * @param Throwable $e Failure raised while running the tool
     * @return string The message the client receives
     */
    private function messageFor(Throwable $e): string {
        return match (true) {
            $e instanceof TalkUnavailableException => Messages::talkUnavailable(),
            $e instanceof ConversationAccessException,
            $e instanceof FileAccessException => $e->getMessage(),
            default => Messages::unexpected(),
        };
    }

    /**
     * @return array{type:object, properties:array<string, mixed>, additionalProperties:bool}
     */
    private function emptySchema(): array {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The conversation token
     * @throws InvalidArgumentException When the token is missing
     */
    private function token(array $arguments): string {
        return (string)($arguments['conversation_token'] ?? throw new InvalidArgumentException(Messages::invalidToken()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return int How many messages to read
     */
    private function limit(array $arguments): int {
        return (int)($arguments['limit'] ?? ConversationReader::DEFAULT_MESSAGES);
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The message body
     * @throws InvalidArgumentException When the message is missing
     */
    private function message(array $arguments): string {
        return (string)($arguments['message'] ?? throw new InvalidArgumentException(Messages::emptyMessage()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string|null The optional caption, or null when the tool was called without one
     */
    private function optionalMessage(array $arguments): ?string {
        return isset($arguments['message']) ? (string)$arguments['message'] : null;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The file path
     * @throws InvalidArgumentException When the path is missing
     */
    private function path(array $arguments): string {
        return (string)($arguments['path'] ?? throw new InvalidArgumentException(Messages::invalidPath()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return int|null The quoted message id, when the reply quotes one
     */
    private function replyTo(array $arguments): ?int {
        return isset($arguments['reply_to']) ? (int)$arguments['reply_to'] : null;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return int The room share id of the attachment
     * @throws InvalidArgumentException When the id is missing
     */
    private function attachmentId(array $arguments): int {
        if (!isset($arguments['attachment_id'])) {
            throw new InvalidArgumentException(Messages::invalidIdentifier());
        }

        return (int)$arguments['attachment_id'];
    }

    /**
     * Every writing tool reads and checks its arguments before anything is resolved, so a malformed call is
     * reported as the client mistake it is instead of reaching a lookup it should never have started.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_reply
     * @throws InvalidArgumentException When an argument is missing
     * @throws ConversationAccessException When the conversation is missing, not writable, or the quoted message is not there
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function replyCall(string $userId, array $arguments): array {

        $resolved = $this->resolveReply($userId, $arguments);

        return $this->writer->reply($resolved['conversation'], $userId, $resolved['message'], $resolved['replyTo']);
    }

    /**
     * The plan of a reply, including the final text: the reference is resolved here too, so what the user reads
     * is the text with the title and the link that will actually be published.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan
     */
    private function replyPreview(string $userId, array $arguments): array {
        $resolved = $this->resolveReply($userId, $arguments);
        $plan = $this->preview->reply(
            $resolved['conversation'],
            $userId,
            $resolved['message'],
            $resolved['replyTo'],
        );

        return $resolved['item'] === null ? $plan : $plan + ['reference' => $resolved['item']];
    }

    /**
     * Everything a reply needs, resolved the same way on the plan and on the confirmed call.
     *
     * The reference is resolved again on the confirmed call instead of trusted from the plan: access is checked
     * at the moment of sending, and a title that changed since the plan changes the text, so the plan the user
     * saw is no longer the text that would go out.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array{conversation:Conversation, message:string, replyTo:int|null, item:array<string, mixed>|null}
     */
    private function resolveReply(string $userId, array $arguments): array {
        $message = $this->message($arguments);
        $reference = $this->reference($arguments);
        $conversation = $this->writable($userId, $arguments);

        $item = $reference === null ? null : $this->references->resolve($userId, $reference);
        if ($item !== null) {
            $message = ReferenceLinker::append($message, $item);
        }

        return [
            'conversation' => $conversation,
            'message' => $message,
            'replyTo' => $this->replyTo($arguments),
            'item' => $item,
        ];
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_attach_file
     * @throws InvalidArgumentException When the path is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function attachCall(string $userId, array $arguments): array {
        $path = $this->path($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        $attached = $this->sharer->attach($conversation, $userId, $path, $caption);
        if (($attached['captionSent'] ?? null) === false) {
            // The card is already in the room, so this is a real failure of the call even though it is not an
            // error result: an operator has to be able to see that the caption was lost.
            $this->logger->error('Talk caption was not sent after the attachment was published', [
                'app' => 'mcp',
                'tool' => self::TOOL_ATTACH,
                'attachmentId' => $attached['attachmentId'],
                'exception' => 'CaptionFailed',
            ]);
        }

        return $attached;
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_attach_file
     * @throws InvalidArgumentException When the path is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    private function attachPreview(string $userId, array $arguments): array {
        return $this->preview->attach(
            $this->writable($userId, $arguments),
            $userId,
            $this->path($arguments),
            $this->optionalMessage($arguments),
        );
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_quote_file
     * @throws InvalidArgumentException When the attachment id is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing, or the attachment is not a share of it
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function quoteCall(string $userId, array $arguments): array {
        $attachmentId = $this->attachmentId($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        return $this->writer->quoteAttachment($conversation, $userId, $attachmentId, $caption);
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_quote_file
     * @throws InvalidArgumentException When the attachment id is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing, or the attachment is not a share of it
     */
    private function quotePreview(string $userId, array $arguments): array {
        return $this->preview->quote(
            $this->writable($userId, $arguments),
            $userId,
            $this->attachmentId($arguments),
            $this->optionalMessage($arguments),
        );
    }

    /**
     * A direct message to an account. The plan resolves the target and stops there, so a user who says no is not
     * left with an empty conversation; the room is created by the confirmed call, and only through the same call
     * the Talk UI uses, which keeps the product's own rule about who may talk to whom.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_message_user
     * @throws InvalidArgumentException When the account id or the message is missing
     * @throws ConversationAccessException When the target is out of reach or the conversation cannot be written in
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function messageUserCall(string $userId, array $arguments): array {
        $targetId = $this->targetUser($arguments);
        $message = $this->message($arguments);


        $conversation = $this->userConversations->conversation($userId, $targetId);
        $result = $this->writer->reply($conversation, $userId, $message, null);

        return $result + ['user' => ['id' => $targetId, 'displayName' => $conversation->displayName($userId)]];
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_message_user
     * @throws InvalidArgumentException When the account id or the message is missing
     * @throws ConversationAccessException When the target is out of reach
     */
    private function messageUserPreview(string $userId, array $arguments): array {
        return $this->preview->directMessage(
            $this->userConversations->target($userId, $this->targetUser($arguments)),
            $userId,
            $this->message($arguments),
        );
    }

    /**
     * A batch of messages in one conversation. The plan carries the whole list, because a batch is approved
     * as a list: a tool that let the agent send that list with one item changed would be sending something
     * else. Every quoted id is resolved while the plan is built, so a wrong citation fails before anything is
     * published rather than halfway through.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_send_batch
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is malformed
     * @throws ConversationAccessException When the conversation cannot be written in or a citation does not exist
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function sendBatchCall(string $userId, array $arguments): array {
        // The shape of the list is a client mistake, so it is checked before any conversation is looked up.
        $items = $this->batchItems($arguments);
        $conversation = $this->resolver->resolveForWriting($userId, $this->token($arguments));


        $result = $this->writer->replyMany($conversation, $userId, $items);

        if ($result['failed'] !== []) {
            // Part of the batch is in the room now, so the result has to tell which: a generic failure would invite
            // a retry that republishes what already went out.
            $this->logger->warning('Talk sent only part of the batch in conversation {token}', [
                'token' => $conversation->token(),
                'sent' => count($result['sent']),
                'failed' => count($result['failed']),
            ]);
        }

        return $result;
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_send_batch
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is malformed
     * @throws ConversationAccessException When the conversation cannot be written in or a citation does not exist
     */
    private function sendBatchPreview(string $userId, array $arguments): array {
        return $this->preview->batch(
            $this->resolver->resolveForWriting($userId, $this->token($arguments)),
            $userId,
            $this->batchItems($arguments),
        );
    }

    /**
     * A new group. Both steps of the product's API happen only here, in the confirmed call: the plan resolves the
     * people who would be invited and stops, so an abandoned preview leaves no room behind.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_create_group
     * @throws InvalidArgumentException When the name or the participant list is not usable
     * @throws ConversationAccessException When an account is out of reach or the product refuses the creation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function createGroupCall(string $userId, array $arguments): array {
        $name = GroupCreator::normalizeName((string)($arguments['name'] ?? ''));
        $participants = $this->participantIds($arguments);


        return $this->groups->create($userId, $name, $participants);
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_create_group
     * @throws InvalidArgumentException When the name or the participant list is not usable
     * @throws ConversationAccessException When an account is out of reach
     */
    private function createGroupPreview(string $userId, array $arguments): array {
        return $this->preview->group(
            $userId,
            GroupCreator::normalizeName((string)($arguments['name'] ?? '')),
            $this->userConversations->contacts($userId, $this->participantIds($arguments), true),
        );
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed>|null Reference object of talk_reply, or null when absent
     * @throws InvalidArgumentException When reference is not an object
     */
    private function reference(array $arguments): ?array {
        $reference = $arguments['reference'] ?? null;
        if ($reference !== null && !is_array($reference)) {
            throw new InvalidArgumentException(Messages::invalidReference());
        }

        return $reference;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return list<string> Accounts the call would invite, in the order received
     * @throws InvalidArgumentException When participants is not a list of account ids
     */
    private function participantIds(array $arguments): array {
        $participants = $arguments['participants'] ?? [];
        if (!is_array($participants) || !array_is_list($participants)) {
            throw new InvalidArgumentException(Messages::invalidUser());
        }

        $ids = [];
        foreach ($participants as $participantId) {
            if (!is_string($participantId)) {
                throw new InvalidArgumentException(Messages::invalidUser());
            }
            $ids[] = $participantId;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return list<array{message:string, replyTo?:int|null>} Messages of the batch, in the order received
     * @throws InvalidArgumentException When messages is not a list of items with a message
     */
    private function batchItems(array $arguments): array {
        $messages = $arguments['messages'] ?? throw new InvalidArgumentException(Messages::emptyBatch());
        if (!is_array($messages) || !array_is_list($messages) || $messages === []) {
            throw new InvalidArgumentException(Messages::emptyBatch());
        }
        // A list longer than a user can read is a mistake in the call, and it is refused here so that neither
        // the plan nor the conversation behind it is built for something nobody could have approved.
        if (count($messages) > WritePreview::MAX_BATCH) {
            throw new InvalidArgumentException(Messages::tooManyMessages(WritePreview::MAX_BATCH));
        }

        $items = [];
        foreach ($messages as $item) {
            if (!is_array($item) || !isset($item['message']) || !is_string($item['message'])) {
                throw new InvalidArgumentException(Messages::emptyMessage());
            }
            $replyTo = $item['reply_to'] ?? null;
            $items[] = ['message' => $item['message'], 'replyTo' => $replyTo === null ? null : (int)$replyTo];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string Account id of the message target
     * @throws InvalidArgumentException When the account is missing
     */
    private function targetUser(array $arguments): string {
        return (string)($arguments['user'] ?? throw new InvalidArgumentException(Messages::invalidUser()));
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return Conversation The conversation, already checked for writing
     * @throws InvalidArgumentException When the token is malformed
     * @throws ConversationAccessException When the conversation is missing or not writable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function writable(string $userId, array $arguments): Conversation {
        return $this->resolver->resolveForWriting($userId, $this->token($arguments));
    }
}
