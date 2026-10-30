<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolResult;

/**
 * The answers of the message tools. Everything a message carries (subject, names, addresses, preview, body, attachment
 * names) was written by whoever sent it, so each answer opens by saying so, in the text the model reads.
 */
final class MailMessageAnswer {
	/**
	 * Receives the row shaper.
	 *
	 * @param MailMessageRows $rows shapes the cached rows
	 * @return void
	 */
	public function __construct(
		private MailMessageRows $rows,
	) {
	}

	/**
	 * A page of a listing or a search.
	 *
	 * @param MailMessageScope $scope the folders read and the ones skipped
	 * @param array{messages:list<array{message:object, accountId:int}>, hasMore:bool, nextCursor:string|null} $page the page
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 */
	public function page(MailMessageScope $scope, array $page): array {
		return ToolResult::json([
			'untrustedMailData' => true,
			'notice' => MailMessages::UNTRUSTED_NOTICE,
			'folders' => array_map(fn (array $folder): array => ['accountId' => $folder['accountId'], 'mailboxId' => $folder['mailboxId'], 'path' => $folder['path'],
				'url' => $this->rows->mailboxUrl($folder['mailboxId'])], $scope->folders),
			'skippedFolders' => $scope->skipped,
			'messages' => array_map(fn (array $item): array => $this->rows->summary($item['message'], $item['accountId']), $page['messages']),
			'count' => count($page['messages']),
			'hasMore' => $page['hasMore'],
			'nextCursor' => $page['nextCursor'],
		]);
	}

	/**
	 * One message with its body.
	 *
	 * @param array<string, mixed> $message the message row
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 */
	public function message(array $message): array {
		return ToolResult::json([
			'untrustedMailData' => true,
			'notice' => MailMessages::UNTRUSTED_NOTICE,
			'message' => $message,
		]);
	}

	/**
	 * The messages of a thread.
	 *
	 * @param int $messageId the message the thread was asked for
	 * @param int $total messages of the thread in the cache
	 * @param list<array<string, mixed>> $rows the rows returned, newest first
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 */
	public function thread(int $messageId, int $total, array $rows): array {
		return ToolResult::json([
			'untrustedMailData' => true,
			'notice' => MailMessages::UNTRUSTED_NOTICE,
			'messageId' => $messageId,
			'messages' => $rows,
			'count' => count($rows),
			'threadSize' => $total,
			'truncated' => count($rows) < $total,
		]);
	}
}
