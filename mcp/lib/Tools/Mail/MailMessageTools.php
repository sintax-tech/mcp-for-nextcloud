<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tools\Mail\Handler\ListMessagesHandler;
use OCA\Mcp\Tools\Mail\Handler\ReadMessageHandler;
use OCA\Mcp\Tools\Mail\Handler\ReadThreadHandler;
use OCA\Mcp\Tools\Mail\Handler\SearchMessagesHandler;
use OCA\Mcp\Tools\Mail\Link\MailLinks;

/**
 * The four message tools of the Mail module: their definitions and the delegation of a call to their handlers.
 *
 * They read, so their operation is `read` and the registry never asks for `confirm` nor marks them as writing. Who may
 * call them is decided by the separate grant `mail.messages` (`grantAnyOf`), which starts denied like every operation
 * but read ({@see \OCA\Mcp\Service\GrantPolicy::defaultGranted()}): the existing `mail.read` grant of accounts and
 * folders never opens the messages. Every tool carries the app id, so it is hidden while Mail is not enabled for the
 * user or is older than MCP supports.
 */
final class MailMessageTools {
	/** Grant operation of the message tools. */
	public const OPERATION = 'messages';

	/**
	 * Receives the Mail classes, the account boundary, the time zone resolver and the Mail app links.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts and folders of the Mail app
	 * @param UserTimezone $timezones time zone of each user
	 * @param MailLinks $links direct links into the Mail app
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
	) {
	}

	/**
	 * The definitions of the four tools; static, so they never depend on the services of an instance.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, grantAnyOf:list<string>, app:string}>
	 */
	public static function definitions(): array {
		$limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => ListMessagesHandler::MAX_LIMIT, 'default' => ListMessagesHandler::DEFAULT_LIMIT,
			'description' => 'Messages per page (default 25, at most 100). A page also takes every message of the date it ends on, so it can be longer.'];
		$cursor = ['type' => 'string', 'minLength' => 1, 'maxLength' => MailCursor::MAX_LENGTH,
			'description' => 'nextCursor of the previous page, with the same folder and filters; omit for the first page.'];
		$texts = static fn (string $what): array => ['type' => 'array', 'maxItems' => MailSearchCriteria::MAX_ADDRESSES,
			'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT],
			'description' => "Fragments of the $what name or address: a message matches when any of them matches (as the Mail app's own search). Different fields must all match."];
		return [
			self::definition(ListMessagesHandler::TOOL, MailMessages::TOOL_LIST_MESSAGES, [
				'mailbox_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the folder, as mail_list_mailboxes returns it.'],
				'limit' => $limit,
				'cursor' => $cursor,
			], ['mailbox_id']),
			self::definition(SearchMessagesHandler::TOOL, MailMessages::TOOL_SEARCH_MESSAGES, [
				'account_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only this account (mail_list_accounts); omit for all of them.'],
				'mailbox_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only this folder (mail_list_mailboxes); omit for every folder.'],
				'from' => $texts('sender'),
				'to' => $texts('recipient'),
				'cc' => $texts('copy recipient'),
				'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT, 'description' => 'Part of the subject.'],
				'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT,
					'description' => 'Text of the message, searched on the mail server (one search per folder, at most '
						. MailMessageScope::MAX_BODY_FOLDERS . ' folders). With subject as well, a message matching either is returned.'],
				'since' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 25,
					'description' => 'Oldest date, inclusive, as the Mail app stores it (when the mail server received the message): YYYY-MM-DD (start of that day in the user\'s time zone) or an ISO 8601 date and time with offset.'],
				'until' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 25,
					'description' => 'Newest date, inclusive, as the Mail app stores it (when the mail server received the message): YYYY-MM-DD (end of that day in the user\'s time zone) or an ISO 8601 date and time with offset.'],
				'unread' => ['type' => 'boolean', 'description' => 'true for unread messages only, false for read ones only.'],
				'flagged' => ['type' => 'boolean', 'description' => 'true for flagged messages only, false for unflagged ones only.'],
				'has_attachments' => ['type' => 'boolean', 'description' => 'true for messages the Mail app found attachments in.'],
				'limit' => $limit,
				'cursor' => $cursor,
			], []),
			self::definition(ReadMessageHandler::TOOL, MailMessages::TOOL_READ_MESSAGE, [
				'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the message, from a listing, a search or a thread.'],
				'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MailBodyText::MAX_CHARS, 'default' => ReadMessageHandler::DEFAULT_MAX_CHARS,
					'description' => 'Most characters of the body returned (default 20000, at most 100000).'],
			], ['message_id']),
			self::definition(ReadThreadHandler::TOOL, MailMessages::TOOL_READ_THREAD, [
				'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of any message of the conversation.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReadThreadHandler::MAX_LIMIT, 'default' => ReadThreadHandler::DEFAULT_LIMIT,
					'description' => 'Most messages returned, newest first (default 50, at most 200).'],
			], ['message_id']),
		];
	}

	/**
	 * Whether a tool name is one of the four.
	 *
	 * @param string $name tool name
	 * @return bool true for a message tool
	 */
	public static function handles(string $name): bool {
		return in_array($name, [ListMessagesHandler::TOOL, SearchMessagesHandler::TOOL, ReadMessageHandler::TOOL, ReadThreadHandler::TOOL], true);
	}

	/**
	 * Delegates a call to the handler of the tool.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments arguments already validated against the schema of the tool
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws InvalidArgumentException for a tool this class does not have
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when a message, folder or account is not found or Mail is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		$zone = $this->timezones->forUser($userId);
		$messages = new MailMessageGateway($this->services);
		$rows = new MailMessageRows($zone, $this->links);
		$answer = new MailMessageAnswer($rows);
		$search = new MailMessageSearch($this->services, $messages);
		$locator = new MailMessageLocator($this->accounts, $messages);
		$now = time();
		return match ($name) {
			ListMessagesHandler::TOOL => (new ListMessagesHandler($this->accounts, $messages, $search, $answer, $now))->handle($arguments, $userId),
			SearchMessagesHandler::TOOL => (new SearchMessagesHandler($this->accounts, $messages, $search, $answer, $zone, $now))->handle($arguments, $userId),
			ReadMessageHandler::TOOL => (new ReadMessageHandler($locator, new MailBodyReader($this->services), $rows, $answer))->handle($arguments, $userId),
			ReadThreadHandler::TOOL => (new ReadThreadHandler($locator, $messages, $rows, $answer))->handle($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * One tool definition with the module, the read operation, the messages grant and the app.
	 *
	 * @param string $name tool name
	 * @param string $description description in tools/list
	 * @param array<string, mixed> $properties input properties
	 * @param list<string> $required required properties
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, grantAnyOf:list<string>, app:string}
	 */
	private static function definition(string $name, string $description, array $properties, array $required): array {
		$schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
		if ($required !== []) {
			$schema['required'] = $required;
		}
		return [
			'name' => $name,
			'description' => $description,
			'module' => MailModule::MODULE,
			'operation' => 'read',
			'grantAnyOf' => [self::OPERATION],
			'app' => MailServices::APP_ID,
			'inputSchema' => $schema,
		];
	}
}
