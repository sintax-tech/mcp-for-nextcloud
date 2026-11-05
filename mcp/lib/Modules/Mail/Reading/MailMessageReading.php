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

/** Builds the message read work with a fresh actor timezone and clock for each call. */
final class MailMessageReading {
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
	 * @param array<string, mixed> $arguments validated message tool arguments
	 * @param string $userId authenticated actor whose account boundary is applied
	 * @return array<string, mixed> MCP result from the dedicated message handler
	 */
	public function listMessages(array $arguments, string $userId): array {
		[$zone, $messages, $rows, $answer, $search, $locator, $now] = $this->forUser($userId);
		return (new ListMessagesHandler($this->accounts, $messages, $search, $answer, $now))->handle($arguments, $userId);
	}

	/**
	 * @param array<string, mixed> $arguments validated message tool arguments
	 * @param string $userId authenticated actor whose account boundary is applied
	 * @return array<string, mixed> MCP result from the dedicated message handler
	 */
	public function searchMessages(array $arguments, string $userId): array {
		[$zone, $messages, $rows, $answer, $search, $locator, $now] = $this->forUser($userId);
		return (new SearchMessagesHandler($this->accounts, $messages, $search, $answer, $zone, $now))->handle($arguments, $userId);
	}

	/**
	 * @param array<string, mixed> $arguments validated message tool arguments
	 * @param string $userId authenticated actor whose account boundary is applied
	 * @return array<string, mixed> MCP result from the dedicated message handler
	 */
	public function readMessage(array $arguments, string $userId): array {
		[$zone, $messages, $rows, $answer, $search, $locator, $now] = $this->forUser($userId);
		return (new ReadMessageHandler($locator, new MailBodyReader($this->services), $rows, $answer))->handle($arguments, $userId);
	}

	/**
	 * @param array<string, mixed> $arguments validated message tool arguments
	 * @param string $userId authenticated actor whose account boundary is applied
	 * @return array<string, mixed> MCP result from the dedicated message handler
	 */
	public function readThread(array $arguments, string $userId): array {
		[$zone, $messages, $rows, $answer, $search, $locator, $now] = $this->forUser($userId);
		return (new ReadThreadHandler($locator, $messages, $rows, $answer))->handle($arguments, $userId);
	}

	/**
	 * @param string $userId authenticated actor whose timezone is read on this call
	 * @return array{\DateTimeZone, MailMessageGateway, MailMessageRows, MailMessageAnswer, MailMessageSearch, MailMessageLocator, int} read collaborators and the current clock
	 */
	private function forUser(string $userId): array {
		$zone = $this->timezones->forUser($userId);
		$messages = new MailMessageGateway($this->services);
		$rows = new MailMessageRows($zone, $this->links);
		$answer = new MailMessageAnswer($rows);
		$search = new MailMessageSearch($this->services, $messages);
		$locator = new MailMessageLocator($this->accounts, $messages);
		$now = time();
		return [$zone, $messages, $rows, $answer, $search, $locator, $now];
	}
}
