<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Reading;

use DateTimeZone;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Locating\MailMessageScope;
use OCA\Mcp\Modules\Mail\Reading\MailMessageSearch;
use OCA\Mcp\Modules\Mail\Reading\MailSearchCriteria;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Backs `mail_search_messages`: one page of the messages that match the filters, newest first, in one folder, in
 * every folder of one account or in every folder of every account the user owns.
 *
 * Each folder is searched by the Mail app's own folder query on its message cache, and the pages of all folders are
 * merged without losing or repeating a message ({@see \OCA\Mcp\Modules\Mail\Reading\MailMessagePager}). Only the body filter
 * contacts the mail server: one IMAP SEARCH per folder, which changes no flag. Folders the cache cannot answer for
 * are listed in the answer, never dropped silently.
 */
final class SearchMessagesHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_search_messages';

	/**
	 * Receives the boundaries, the search, the answer shaper and the user's time zone.
	 *
	 * @param MailGateway $accounts the accounts of the user
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @param MailMessageSearch $search pages of the cache
	 * @param MailMessageAnswer $answer the untrusted answer envelope
	 * @param DateTimeZone $zone time zone of the user, for dates without a time
	 * @param int $now current Unix time, for the synchronization locks of the Mail app
	 * @return void
	 */
	public function __construct(
		private MailGateway $accounts,
		private MailMessageGateway $messages,
		private MailMessageSearch $search,
		private MailMessageAnswer $answer,
		private DateTimeZone $zone,
		private int $now,
	) {
	}

	/**
	 * Searches one page.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: account_id, mailbox_id, the filters, limit, cursor
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when an account or folder is not the user's, a filter or the cursor is invalid, or the search
	 *     covers more folders than allowed
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$criteria = MailSearchCriteria::fromArguments($arguments, $this->zone);
		$scope = MailMessageScope::resolve($this->accounts, $this->messages, $userId,
			isset($arguments['account_id']) ? (int)$arguments['account_id'] : null,
			isset($arguments['mailbox_id']) ? (int)$arguments['mailbox_id'] : null,
			$criteria->searchesBody(), $this->now);
		$page = $this->search->page($userId, $scope, $criteria, (int)($arguments['limit'] ?? ListMessagesHandler::DEFAULT_LIMIT),
			isset($arguments['cursor']) ? (string)$arguments['cursor'] : null);
		return $this->answer->page($scope, $page);
	}
}
