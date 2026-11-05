<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk;
use OCA\Mcp\Modules\Talk\{Attachments\AttachmentMessage, Locating\Conversation, Failures\ConversationAccessException, Reading\ConversationReader, Locating\ConversationResolver, Writing\ConversationWriter, Failures\FileAccessException, Attachments\FileSharer, Groups\GroupCreator, Presentation\Messages, Presentation\TalkPlanRenderer, Integration\TalkServices, Failures\TalkUnavailableException, Presentation\ToolResult, Locating\UserConversationResolver, Locating\UserFileResolver, Writing\WritePreview};
use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolModule;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
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

	/**
	 * @param TalkServices $talkServices existing Talk operation collaborator
	 * @param ConversationReader $reader existing Talk operation collaborator
	 * @param ConversationResolver $resolver existing Talk operation collaborator
	 * @param ConversationWriter $writer existing Talk operation collaborator
	 * @param FileSharer $sharer existing Talk operation collaborator
	 * @param WritePreview $preview existing Talk operation collaborator
	 * @param UserConversationResolver $userConversations existing Talk operation collaborator
	 * @param GroupCreator $groups existing Talk operation collaborator
	 * @param ReferenceLinker $references existing Talk operation collaborator
	 * @param LoggerInterface $logger existing Talk operation collaborator
	 * @param TalkArguments $arguments existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Replying\ReplyHandler $replyHandler existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Replying\ReplyPreview $replyPlans existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Attaching\AttachFileHandler $attachFileHandler existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Attaching\AttachFilePreview $attachFilePlans existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Quoting\QuoteFileHandler $quoteFileHandler existing Talk operation collaborator
	 * @param \OCA\Mcp\Modules\Talk\Quoting\QuoteFilePreview $quoteFilePlans existing Talk operation collaborator
	 */
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
		private TalkArguments $arguments,
		private \OCA\Mcp\Modules\Talk\Replying\ReplyHandler $replyHandler,
		private \OCA\Mcp\Modules\Talk\Replying\ReplyPreview $replyPlans,
		private \OCA\Mcp\Modules\Talk\Attaching\AttachFileHandler $attachFileHandler,
		private \OCA\Mcp\Modules\Talk\Attaching\AttachFilePreview $attachFilePlans,
		private \OCA\Mcp\Modules\Talk\Quoting\QuoteFileHandler $quoteFileHandler,
		private \OCA\Mcp\Modules\Talk\Quoting\QuoteFilePreview $quoteFilePlans,
	) {
	}

	/**
	 * @return list<array{name:string, description:string, inputSchema:array, module:string, operation:string, app:string}>
	 */
	public function definitions(): array {
		return [
			[
				'name' => self::TOOL_LIST,
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_LIST_CONVERSATIONS,
				'inputSchema' => $this->emptySchema(),
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => self::APP,
			],
			[
				'name' => self::TOOL_READ,
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_READ_MESSAGES,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_REPLY,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_ATTACH_FILE,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_QUOTE_FILE,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_MESSAGE_USER,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_SEND_BATCH,
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
				'description' => \OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions::TOOL_CREATE_GROUP,
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
		$targetId = $this->arguments->targetUser($arguments);
		$message = $this->arguments->message($arguments);

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
			$this->userConversations->target($userId, $this->arguments->targetUser($arguments)),
			$userId,
			$this->arguments->message($arguments),
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
		$items = $this->arguments->batchItems($arguments);
		$conversation = $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));

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
			$this->resolver->resolveForWriting($userId, $this->arguments->token($arguments)),
			$userId,
			$this->arguments->batchItems($arguments),
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
		$participants = $this->arguments->participantIds($arguments);

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
			$this->userConversations->contacts($userId, $this->arguments->participantIds($arguments), true),
		);
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
		return $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));
	}

	/**
	 * The message that explains the failure, for the server log only: a refusal wraps the real cause, so the
	 * cause is the one worth reading. Sanitized like the registry's, with no paths and a length limit.
	 *
	 * @param Throwable $e the exception being logged
	 * @return string sanitized message
	 */
	private static function loggable(Throwable $e): string {
		return \OCA\Mcp\Mcp\Tools\ToolRegistry::loggable($e->getPrevious() ?? $e);
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
		if ($name === 'talk_reply') {
			return $this->replyHandler->handle($arguments, $userId);
		}
		if ($name === 'talk_attach_file') {
			return $this->attachFileHandler->handle($arguments, $userId);
		}
		if ($name === 'talk_quote_file') {
			return $this->quoteFileHandler->handle($arguments, $userId);
		}
		try {
			return match ($name) {
				self::TOOL_LIST => ToolResult::success(['conversations' => $this->reader->listConversations($userId)]),
				self::TOOL_READ => ToolResult::success(['messages' => $this->reader->readMessages($userId, $this->arguments->token($arguments), $this->arguments->limit($arguments))]),
				'talk_message_user' => ToolResult::success($this->messageUserCall($userId, $arguments)),
				'talk_send_batch' => ToolResult::success($this->sendBatchCall($userId, $arguments)),
				'talk_create_group' => ToolResult::success($this->createGroupCall($userId, $arguments)),
				default => throw new InvalidArgumentException(Messages::unknownTool()),
			};
		} catch (InvalidArgumentException $e) {
			// The registry turns this into -32602, and it is a client mistake, not an error worth logging.
			throw $e;
		} catch (PolicyUnavailable $e) {
			throw $e; // the endpoint's 503, as in preview()
		} catch (Throwable $e) {
			$this->logger->error('Talk tool failed', [
				'app' => 'mcp',
				'tool' => $name,
				'exception' => $e::class,
				'exception_message' => self::loggable($e),
			]);

			return ToolResult::error($this->messageFor($e));
		}
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
		if ($name === 'talk_reply') {
			return $this->replyPlans->plan($arguments, $userId);
		}
		if ($name === 'talk_attach_file') {
			return $this->attachFilePlans->plan($arguments, $userId);
		}
		if ($name === 'talk_quote_file') {
			return $this->quoteFilePlans->plan($arguments, $userId);
		}
		try {
			return match ($name) {
				'talk_message_user' => $this->messageUserPreview($userId, $arguments),
				'talk_send_batch' => $this->sendBatchPreview($userId, $arguments),
				'talk_create_group' => $this->createGroupPreview($userId, $arguments),
				default => throw new InvalidArgumentException(Messages::unknownTool()),
			};
		} catch (InvalidArgumentException $e) {
			throw $e;
		} catch (PolicyUnavailable $e) {
			// A policy that cannot be read now (the Files scope of an attachment) is the endpoint's 503, never a
			// Talk failure whose message could differ between an existing and a missing file.
			throw $e;
		} catch (Throwable $e) {
			// The plan says what the confirmed call would say: a missing conversation or file is reported as
			// such, not as the registry's generic failure.
			$this->logger->error('Talk tool failed', [
				'app' => 'mcp',
				'tool' => $name,
				'exception' => $e::class,
				'exception_message' => self::loggable($e),
			]);

			throw new ToolFailure($this->messageFor($e));
		}
	}
}
