<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\ToolModule;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The five tools of the talk module, routed by name.
 *
 * The module holds no access rule of its own: the registry checks the grant and the app enablement, and every
 * resource decision belongs to ConversationResolver, UserFileResolver or the share lookup. What it does own is the
 * translation of a failure into an MCP result, keeping every user-facing string in Messages.
 */
class TalkModule implements ToolModule {
    public const TOOL_LIST = 'talk_list_conversations';
    public const TOOL_READ = 'talk_read_messages';
    public const TOOL_REPLY = 'talk_reply';
    public const TOOL_ATTACH = 'talk_attach_file';
    public const TOOL_QUOTE = 'talk_quote_file';

    private const MODULE = 'talk';
    private const APP = TalkServices::APP_ID;

    public function __construct(
        private TalkServices $talkServices,
        private ConversationReader $reader,
        private ConversationResolver $resolver,
        private ConversationWriter $writer,
        private FileSharer $sharer,
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
                        'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
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
                        'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                        'message' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => ConversationWriter::MAX_MESSAGE_LENGTH,
                        ],
                        'reply_to' => ['type' => 'integer', 'minimum' => 1],
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
                        'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
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
                        'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
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
                self::TOOL_REPLY => ToolResult::success($this->writer->reply(
                    $this->writable($userId, $arguments),
                    $userId,
                    $this->message($arguments),
                    $this->replyTo($arguments),
                )),
                self::TOOL_ATTACH => ToolResult::success($this->sharer->attach(
                    $this->writable($userId, $arguments),
                    $userId,
                    $this->path($arguments),
                    $this->optionalMessage($arguments),
                )),
                self::TOOL_QUOTE => ToolResult::success($this->writer->quoteAttachment(
                    $this->writable($userId, $arguments),
                    $userId,
                    $this->attachmentId($arguments),
                    $this->optionalMessage($arguments),
                )),
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
            $e instanceof ConversationAccessException, $e instanceof FileAccessException => $e->getMessage(),
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
