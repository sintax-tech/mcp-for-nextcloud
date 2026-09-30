<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
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
 * Every writing tool stops at a draft until the user approves it: a call without confirm answers with the draft
 * and an approvalId, and a call with confirm: true only publishes when that id proves a draft of this same account,
 * conversation, action and payload was already shown and has not been spent. Without confirm the tool answers with
 * a payload, never with an error, and writes nothing.
 */
class TalkModule implements ToolModule {
    public const TOOL_LIST = 'talk_list_conversations';
    public const TOOL_READ = 'talk_read_messages';
    public const TOOL_REPLY = 'talk_reply';
    public const TOOL_ATTACH = 'talk_attach_file';
    public const TOOL_QUOTE = 'talk_quote_file';
    public const TOOL_MESSAGE_USER = 'talk_message_user';
    public const TOOL_SEND_BATCH = 'talk_send_batch';

    private const MODULE = 'talk';
    private const APP = TalkServices::APP_ID;

    /** Optional on purpose: a call without it is the draft, a call with it true is the approved send. */
    private const CONFIRM_SCHEMA = ['type' => 'boolean'];
    /** Id of the draft being approved; meaningful only together with confirm: true. */
    private const APPROVAL_ID_SCHEMA = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
    /** Public conversation token; Talk tokens are 30 characters at most, so this only bounds the payload. */
    private const TOKEN_SCHEMA = ['type' => 'string', 'minLength' => 1, 'maxLength' => 30];

    public function __construct(
        private TalkServices $talkServices,
        private ConversationReader $reader,
        private ConversationResolver $resolver,
        private ConversationWriter $writer,
        private FileSharer $sharer,
        private DraftApproval $draftApproval,
        private UserConversationResolver $userConversations,
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
                        'confirm' => self::CONFIRM_SCHEMA,
                        'approval_id' => self::APPROVAL_ID_SCHEMA,
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
                        'confirm' => self::CONFIRM_SCHEMA,
                        'approval_id' => self::APPROVAL_ID_SCHEMA,
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
                        'confirm' => self::CONFIRM_SCHEMA,
                        'approval_id' => self::APPROVAL_ID_SCHEMA,
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
                        'confirm' => self::CONFIRM_SCHEMA,
                        'approval_id' => self::APPROVAL_ID_SCHEMA,
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
                            'maxItems' => DraftApproval::MAX_BATCH,
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
                        'confirm' => self::CONFIRM_SCHEMA,
                        'approval_id' => self::APPROVAL_ID_SCHEMA,
                    ],
                    'required' => ['conversation_token', 'messages'],
                    'additionalProperties' => false,
                ],
                'module' => self::MODULE,
                'operation' => 'reply',
                'app' => self::APP,
            ],
        ];
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
                default => throw new InvalidArgumentException(Messages::UNKNOWN_TOOL),
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
            $e instanceof TalkUnavailableException => Messages::TALK_UNAVAILABLE,
            $e instanceof ConversationAccessException,
            $e instanceof FileAccessException,
            $e instanceof ApprovalException => $e->getMessage(),
            default => Messages::UNEXPECTED,
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
        return (string)($arguments['conversation_token'] ?? throw new InvalidArgumentException(Messages::INVALID_TOKEN));
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
        return (string)($arguments['message'] ?? throw new InvalidArgumentException(Messages::EMPTY_MESSAGE));
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
        return (string)($arguments['path'] ?? throw new InvalidArgumentException(Messages::INVALID_PATH));
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
            throw new InvalidArgumentException(Messages::INVALID_IDENTIFIER);
        }

        return (int)$arguments['attachment_id'];
    }

    /**
     * Every writing tool reads and checks its arguments before anything is resolved, so a malformed call is
     * reported as the client mistake it is instead of reaching a lookup it should never have started.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_reply, or the draft when confirm is absent
     * @throws InvalidArgumentException When an argument is missing
     * @throws ConversationAccessException When the conversation is missing, not writable, or the quoted message is not there
     * @throws ApprovalException When the confirmed call carries no usable approval of this draft
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function replyCall(string $userId, array $arguments): array {
        $message = $this->message($arguments);
        $replyTo = $this->replyTo($arguments);
        $conversation = $this->writable($userId, $arguments);

        if (!$this->confirmed($arguments)) {
            return $this->draftApproval->reply($conversation, $userId, $message, $replyTo);
        }
        $this->draftApproval->approveReply($conversation, $userId, $this->approvalId($arguments), $message, $replyTo);

        return $this->writer->reply($conversation, $userId, $message, $replyTo);
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_attach_file, or the draft when confirm is absent
     * @throws InvalidArgumentException When the path is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     * @throws ApprovalException When the confirmed call carries no usable approval of this draft
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function attachCall(string $userId, array $arguments): array {
        $path = $this->path($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        if (!$this->confirmed($arguments)) {
            return $this->draftApproval->attach($conversation, $userId, $path, $caption);
        }
        $this->draftApproval->approveAttach($conversation, $userId, $this->approvalId($arguments), $path, $caption);

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
     * @return array<string, mixed> Result data of talk_quote_file, or the draft when confirm is absent
     * @throws InvalidArgumentException When the attachment id is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing, or the attachment is not a share of it
     * @throws ApprovalException When the confirmed call carries no usable approval of this draft
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function quoteCall(string $userId, array $arguments): array {
        $attachmentId = $this->attachmentId($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        if (!$this->confirmed($arguments)) {
            return $this->draftApproval->quote($conversation, $userId, $attachmentId, $caption);
        }
        $this->draftApproval->approveQuote($conversation, $userId, $this->approvalId($arguments), $attachmentId, $caption);

        return $this->writer->quoteAttachment($conversation, $userId, $attachmentId, $caption);
    }

    /**
     * A direct message to an account. The draft resolves the target and stops there, so a user who never approves
     * is not left with an empty conversation; the room is created by the approved call, and only through the same
     * call the Talk UI uses, which keeps the product's own rule about who may talk to whom.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_message_user, or the draft when confirm is absent
     * @throws InvalidArgumentException When the account id or the message is missing
     * @throws ConversationAccessException When the target is out of reach or the conversation cannot be written in
     * @throws ApprovalException When the confirmed call carries no usable approval of this draft
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function messageUserCall(string $userId, array $arguments): array {
        $targetId = $this->targetUser($arguments);
        $message = $this->message($arguments);

        if (!$this->confirmed($arguments)) {
            return $this->draftApproval->directMessage($this->userConversations->target($userId, $targetId), $userId, $message);
        }

        // The approval comes first: it binds the target account and the text, and it is spent before the room
        // exists, so a confirmed call cannot create a conversation the user never approved.
        $this->draftApproval->approveDirectMessage($userId, $this->approvalId($arguments), $targetId, $message);

        $conversation = $this->userConversations->conversation($userId, $targetId);
        $result = $this->writer->reply($conversation, $userId, $message, null);

        return $result + ['user' => ['id' => $targetId, 'displayName' => $conversation->displayName($userId)]];
    }

    /**
     * A batch of messages in one conversation. The draft carries the whole list, because the approval of a batch is
     * the approval of that list: a tool that let the agent send the approved list with one item changed would be
     * sending something else. Every quoted id is resolved while the draft is built, so a wrong citation fails
     * before anything is published rather than halfway through.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_send_batch, or the draft when confirm is absent
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is malformed
     * @throws ConversationAccessException When the conversation cannot be written in or a citation does not exist
     * @throws ApprovalException When the confirmed call carries no usable approval of this draft
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function sendBatchCall(string $userId, array $arguments): array {
        // The shape of the list is a client mistake, so it is checked before any conversation is looked up.
        $items = $this->batchItems($arguments);
        $conversation = $this->resolver->resolveForWriting($userId, $this->token($arguments));

        if (!$this->confirmed($arguments)) {
            return $this->draftApproval->batch($conversation, $userId, $items);
        }

        $this->draftApproval->approveBatch($conversation, $userId, $this->approvalId($arguments), $items);
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
     * @param array<string, mixed> $arguments Validated arguments
     * @return list<array{message:string, replyTo?:int|null>} Messages of the batch, in the order received
     * @throws InvalidArgumentException When messages is not a list of items with a message
     */
    private function batchItems(array $arguments): array {
        $messages = $arguments['messages'] ?? throw new InvalidArgumentException(Messages::EMPTY_BATCH);
        if (!is_array($messages) || !array_is_list($messages) || $messages === []) {
            throw new InvalidArgumentException(Messages::EMPTY_BATCH);
        }

        $items = [];
        foreach ($messages as $item) {
            if (!is_array($item) || !isset($item['message']) || !is_string($item['message'])) {
                throw new InvalidArgumentException(Messages::EMPTY_MESSAGE);
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
        return (string)($arguments['user'] ?? throw new InvalidArgumentException(Messages::INVALID_USER));
    }

    /**
     * Only an explicit true publishes; confirm: false is the same call as no confirm at all.
     *
     * @param array<string, mixed> $arguments Validated arguments
     */
    private function confirmed(array $arguments): bool {
        return ($arguments['confirm'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string|null Id of the draft being approved, or null when the caller sent none
     */
    private function approvalId(array $arguments): ?string {
        return isset($arguments['approval_id']) ? (string)$arguments['approval_id'] : null;
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
