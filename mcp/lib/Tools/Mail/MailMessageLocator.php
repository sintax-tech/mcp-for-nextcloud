<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Resolves a message id to the message, its folder and its account, each through the user's own accounts, on every
 * call. A message of another user, of a delegated account, of a folder that is gone, or one that does not exist all
 * answer the same "not found", so the answer never tells which ids exist.
 */
final class MailMessageLocator {
	/**
	 * Receives the two Mail boundaries.
	 *
	 * @param MailGateway $accounts the accounts of the user
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @return void
	 */
	public function __construct(
		private MailGateway $accounts,
		private MailMessageGateway $messages,
	) {
	}

	/**
	 * The message with its folder and account.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $messageId message id from a listing, a search or a thread
	 * @return array{message:object, mailbox:object, account:object} the Mail objects
	 * @throws ToolFailure when the message is not one of the user's own accounts
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function locate(string $userId, int $messageId): array {
		$message = $this->messages->message($userId, $messageId);
		$mailbox = $message === null ? null : $this->messages->mailbox($userId, (int)$message->getMailboxId());
		$account = $mailbox === null ? null : $this->accounts->ownedAccount($userId, (int)$mailbox->getAccountId());
		if ($message === null || $mailbox === null || $account === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		return ['message' => $message, 'mailbox' => $mailbox, 'account' => $account];
	}
}
