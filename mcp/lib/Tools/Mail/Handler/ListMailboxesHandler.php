<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/**
 * Backs `mail_list_mailboxes`: one page of the folders the Mail app cached for an account of the user.
 *
 * An account of somebody else, a delegated one and one that does not exist get the same "not found", so the answer
 * never tells which ids exist. The folders come from the cache only; an account whose folders were never cached
 * answers with an empty page and a note instead of a synchronization.
 */
final class ListMailboxesHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_list_mailboxes';

	/** Page size when the caller sends no limit. */
	public const DEFAULT_LIMIT = 200;

	/** Largest page accepted. */
	public const MAX_LIMIT = 500;

	/**
	 * Receives the Mail boundary and the row shaper.
	 *
	 * @param MailGateway $gateway read-only access to the accounts and the folder cache of the Mail app
	 * @param MailRows $rows cleans the untrusted names and orders the folders
	 * @return void
	 */
	public function __construct(
		private MailGateway $gateway,
		private MailRows $rows = new MailRows(),
	) {
	}

	/**
	 * Lists one page of folders of an account of the user.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: account_id, limit, offset
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the account is not one the user owns
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$account = $this->gateway->ownedAccount($userId, (int)$arguments['account_id']);
		if ($account === null) {
			throw new ToolFailure(MailMessages::accountNotFound());
		}
		$limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
		$offset = (int)($arguments['offset'] ?? 0);
		$all = $this->rows->sorted(array_map($this->rows->mailbox(...), $this->gateway->mailboxes($userId, $account)));
		$page = array_slice($all, $offset, $limit);
		$more = $offset + count($page) < count($all);
		$answer = [
			'account' => $this->rows->account($this->gateway->accountRow($account)),
			'mailboxes' => $page,
			'mailboxCount' => count($all),
			'hasMore' => $more,
			'nextOffset' => $more ? $offset + count($page) : null,
		];
		if ($all === []) {
			$answer['note'] = MailMessages::notSynchronized();
		}
		return ToolResult::json($answer);
	}
}
