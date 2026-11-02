<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Reading;

use OCA\Mcp\Modules\Mail\Integration\MailBodyReader;
use OCA\Mcp\Modules\Mail\Presentation\MailBodyText;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCA\Mcp\Modules\Mail\Locating\MailMessageScope;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\Integration\MailServices;

use InvalidArgumentException;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Mail\Reading\ListMessagesHandler;
use OCA\Mcp\Modules\Mail\Reading\ReadMessageHandler;
use OCA\Mcp\Modules\Mail\Reading\ReadThreadHandler;
use OCA\Mcp\Modules\Mail\Reading\SearchMessagesHandler;
use OCA\Mcp\Modules\Mail\MailLinks;

/**
 * The four message tools of the Mail module: their definitions and the delegation of a call to their handlers.
 *
 * They read, so their operation is `read` and the registry never asks for `confirm` nor marks them as writing. Who may
 * call them is decided by the separate grant `mail.messages` (`grantAnyOf`), which starts denied like every operation
 * but read ({@see \OCA\Mcp\Access\Grants\GrantPolicy::defaultGranted()}): the existing `mail.read` grant of accounts and
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
		return [\OCA\Mcp\Modules\Mail\Tools\ListMessages::definition()->toArray(), \OCA\Mcp\Modules\Mail\Tools\SearchMessages::definition()->toArray(), \OCA\Mcp\Modules\Mail\Tools\ReadMessage::definition()->toArray(), \OCA\Mcp\Modules\Mail\Tools\ReadThread::definition()->toArray()];
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
		$work = new MailMessageReading($this->services, $this->accounts, $this->timezones, $this->links);
		return match ($name) {
			ListMessagesHandler::TOOL => $work->listMessages($arguments, $userId),
			SearchMessagesHandler::TOOL => $work->searchMessages($arguments, $userId),
			ReadMessageHandler::TOOL => $work->readMessage($arguments, $userId),
			ReadThreadHandler::TOOL => $work->readThread($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

}
