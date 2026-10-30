<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\Mail\MailMessageAnswer;
use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailMessageRows;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Backs `mail_read_thread`: the messages of the conversation one message belongs to, as the Mail app threaded them in
 * its cache, within that message's account, newest first and without bodies. A message the Mail app has not threaded
 * is a conversation of its own.
 */
final class ReadThreadHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_read_thread';

	/** Messages returned when the caller sends no limit. */
	public const DEFAULT_LIMIT = 50;

	/** Largest number of messages accepted. */
	public const MAX_LIMIT = 200;

	/**
	 * Receives the locator, the Mail boundary and the shapers.
	 *
	 * @param MailMessageLocator $locator message id to the user's own message, folder and account
	 * @param MailMessageGateway $messages the thread of the Mail app
	 * @param MailMessageRows $rows shapes the cached rows
	 * @param MailMessageAnswer $answer the untrusted answer envelope
	 * @return void
	 */
	public function __construct(
		private MailMessageLocator $locator,
		private MailMessageGateway $messages,
		private MailMessageRows $rows,
		private MailMessageAnswer $answer,
	) {
	}

	/**
	 * Reads the thread of one message.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, limit
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ToolFailure when the message is not the user's
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		$message = $found['message'];
		$account = $found['account'];
		$root = (string)($message->getThreadRootId() ?? '');
		$thread = $root === '' ? [$message] : $this->messages->thread($userId, $account, $root);
		usort($thread, static fn (object $a, object $b): int => [(int)$b->getSentAt(), (int)$b->getId()] <=> [(int)$a->getSentAt(), (int)$a->getId()]);
		$limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
		$accountId = (int)$account->getId();
		return $this->answer->thread((int)$message->getId(), count($thread),
			array_map(fn (object $item): array => $this->rows->summary($item, $accountId), array_slice($thread, 0, $limit)));
	}
}
