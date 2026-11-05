<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Sending;

use OCA\Mcp\Modules\Mail\Presentation\MailSendMessages;

use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The account a message is sent from, as the Mail app's send chain needs it: one of the user's own accounts (another
 * user's account, or one delegated to the user, reads like a missing one), with the Sent folder its settings name
 * (MailAccount::getSentMailboxId()) among its own folders. The chain's first handler stops a message without one
 * (STATUS_NO_SENT_MAILBOX), so it is refused here, before any plan, and no message is written to the Outbox.
 *
 * The sender is the account's own name and address (Account::getName(), getEMailAddress()), what
 * MailTransmission::sendMessage() uses without an alias.
 */
final class MailSendAccount {
	/**
	 * Receives the account boundary and the folder lookup.
	 *
	 * @param MailGateway $accounts the user's own accounts
	 * @param MailMessageGateway $messages the folders of the Mail app
	 * @return void
	 */
	public function __construct(
		private MailGateway $accounts,
		private MailMessageGateway $messages,
	) {
	}

	/**
	 * The account, its Sent folder and the sender.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $accountId account id asked for
	 * @return array{account:object, sent:object, senderName:string, senderAddress:string} the account, its Sent folder
	 *     and the sender
	 * @throws ToolFailure when the account is not the user's, or has no Sent folder of its own
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function resolve(string $userId, int $accountId): array {
		$account = $this->accounts->ownedAccount($userId, $accountId) ?? throw new ToolFailure(MailMessages::accountNotFound());
		$sentId = $account->getMailAccount()->getSentMailboxId();
		$sent = $sentId === null ? null : $this->messages->mailbox($userId, (int)$sentId);
		if ($sent === null || (int)$sent->getAccountId() !== (int)$account->getId()) {
			throw new ToolFailure(MailSendMessages::noSentFolder());
		}
		return ['account' => $account, 'sent' => $sent, 'senderName' => (string)$account->getName(), 'senderAddress' => (string)$account->getEMailAddress()];
	}
}
