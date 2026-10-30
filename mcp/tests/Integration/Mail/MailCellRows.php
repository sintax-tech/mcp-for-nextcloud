<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * What the Mail integration tests read of a Mail cell: the rows the Mail app keeps for the fixture accounts (to find
 * ids and to prove the tables did not change), a logged-in IMAP probe of alice's mailbox, and the observation lines the
 * harness collects next to the IMAP rawlogs. For tests whose base class has official(), the server's own database
 * connection.
 */
trait MailCellRows {
	/**
	 * Appends a line with the current time to the observations the harness collects, so the IMAP rawlogs of the run
	 * can be matched to the tool calls.
	 *
	 * @param string $what what happens now
	 * @return void
	 */
	private static function markCell(string $what): void {
		file_put_contents('/tmp/mcp-it-observations.txt', sprintf("%s at=%.3f\n", $what, microtime(true)), FILE_APPEND);
	}

	/**
	 * The JSON answer of a successful call.
	 *
	 * @param array<string, mixed> $result MCP result
	 * @return array<string, mixed> the answer
	 */
	private function decoded(array $result): array {
		$this->assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * A logged-in probe of alice's mailbox on the IMAP server of the cell.
	 *
	 * @return ImapProbe the probe
	 */
	private function imap(): ImapProbe {
		return new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), 'alice', (string)getenv('MCP_IT_IMAP_PASSWORD'));
	}

	/**
	 * The only Mail account of a fixture user.
	 *
	 * @param string $uid fixture user
	 * @return int account id
	 */
	private function account(string $uid): int {
		$rows = $this->select('mail_accounts', 'user_id', $uid, 'id');
		$this->assertCount(1, $rows);
		return (int)$rows[0]['id'];
	}

	/**
	 * The id of a cached folder of an account.
	 *
	 * @param int $accountId account id
	 * @param string $name folder name
	 * @return int folder id
	 */
	private function mailbox(int $accountId, string $name): int {
		foreach ($this->select('mail_mailboxes', 'account_id', $accountId, 'id') as $row) {
			if ($row['name'] === $name) {
				return (int)$row['id'];
			}
		}
		$this->fail("folder $name was not synchronized");
	}

	/**
	 * Ids of the cached messages of a folder that are not marked deleted, sorted.
	 *
	 * @param int $mailboxId folder id
	 * @return list<int> message ids
	 */
	private function messageIds(int $mailboxId): array {
		$ids = array_map(static fn (array $row): int => (int)$row['id'],
			array_filter($this->select('mail_messages', 'mailbox_id', $mailboxId, 'id'), static fn (array $row): bool => !(bool)$row['flag_deleted']));
		sort($ids);
		return array_values($ids);
	}

	/**
	 * Message ids of a folder by subject.
	 *
	 * @param int $mailboxId folder id
	 * @return array<string, int> id by subject
	 */
	private function subjects(int $mailboxId): array {
		return array_column(array_map(static fn (array $row): array => ['s' => $row['subject'], 'id' => (int)$row['id']],
			$this->select('mail_messages', 'mailbox_id', $mailboxId, 'id')), 'id', 's');
	}

	/**
	 * Every row the Mail app keeps for an account: the account, its folders, their messages and recipients.
	 *
	 * @param int $accountId account id
	 * @return array<string, list<array<string, mixed>>> the rows
	 */
	private function cache(int $accountId): array {
		$mailboxes = $this->select('mail_mailboxes', 'account_id', $accountId, 'id');
		$messages = [];
		$recipients = [];
		foreach ($mailboxes as $mailbox) {
			foreach ($this->select('mail_messages', 'mailbox_id', (int)$mailbox['id'], 'id') as $message) {
				$messages[] = $message;
				array_push($recipients, ...$this->select('mail_recipients', 'message_id', (int)$message['id'], 'id'));
			}
		}
		return ['account' => $this->select('mail_accounts', 'id', $accountId, 'id'), 'mailboxes' => $mailboxes, 'messages' => $messages, 'recipients' => $recipients];
	}

	/**
	 * Hash of every row the Mail app keeps for an account, so a mismatch never prints a row.
	 *
	 * @param int $accountId account id
	 * @return string the hash
	 */
	private function cacheHash(int $accountId): string {
		return hash('sha256', json_encode($this->cache($accountId), JSON_THROW_ON_ERROR));
	}

	/**
	 * All columns of the rows of a table whose column equals the value.
	 *
	 * @param string $table table without prefix
	 * @param string $column filtered column
	 * @param int|string $value value
	 * @param string $order column to order by
	 * @return list<array<string, mixed>> the rows
	 */
	private function select(string $table, string $column, int|string $value, string $order): array {
		$qb = $this->official()->getQueryBuilder();
		$result = $qb->select('*')->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)))
			->orderBy($order)
			->executeQuery();
		try {
			return $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}
}
