<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessageAnswer;
use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailMessageScope;
use OCA\Mcp\Tools\Mail\MailMessageSearch;
use OCA\Mcp\Tools\Mail\MailSearchCriteria;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Backs `mail_list_messages`: one page of the messages of one folder of the user, newest first, from the message
 * cache of the Mail app. No preview is computed, no folder is synchronized and the mail server is not contacted.
 */
final class ListMessagesHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_list_messages';

	/** Page size when the caller sends no limit. */
	public const DEFAULT_LIMIT = 25;

	/** Largest page accepted. */
	public const MAX_LIMIT = 100;

	/**
	 * Receives the boundaries, the search and the answer shaper.
	 *
	 * @param MailGateway $accounts the accounts of the user
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @param MailMessageSearch $search pages of the cache
	 * @param MailMessageAnswer $answer the untrusted answer envelope
	 * @param int $now current Unix time, for the synchronization locks of the Mail app
	 * @return void
	 */
	public function __construct(
		private MailGateway $accounts,
		private MailMessageGateway $messages,
		private MailMessageSearch $search,
		private MailMessageAnswer $answer,
		private int $now,
	) {
	}

	/**
	 * Lists one page of a folder.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: mailbox_id, limit, cursor
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the folder is not the user's or cannot be read now, or the cursor is invalid
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$scope = MailMessageScope::resolve($this->accounts, $this->messages, $userId, null, (int)$arguments['mailbox_id'], false, $this->now);
		$page = $this->search->page($userId, $scope, new MailSearchCriteria(), (int)($arguments['limit'] ?? self::DEFAULT_LIMIT),
			isset($arguments['cursor']) ? (string)$arguments['cursor'] : null);
		return $this->answer->page($scope, $page);
	}
}
