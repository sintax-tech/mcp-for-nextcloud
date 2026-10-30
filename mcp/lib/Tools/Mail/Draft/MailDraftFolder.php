<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Draft;

use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * The Drafts folder of an account, as the Mail app's own draft save finds it: the folder the account settings name
 * (MailAccount::getDraftsMailboxId(), set by the Mail app from the folder's role or name when it synchronizes, or chosen
 * by the user). MailTransmission::saveDraft() refuses an account without one, and so does this class, before any plan: it
 * creates no folder. The folder is read through the user's own folders, so it is always one of the account's.
 */
final class MailDraftFolder {
	/**
	 * Receives the folder lookup.
	 *
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @return void
	 */
	public function __construct(
		private MailMessageGateway $messages,
	) {
	}

	/**
	 * The Drafts folder of the account.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @return object OCA\Mail\Db\Mailbox of that account
	 * @throws ToolFailure when the account has no Drafts folder, or the one it names is not one of its folders
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function of(string $userId, object $account): object {
		$id = $account->getMailAccount()->getDraftsMailboxId();
		$mailbox = $id === null ? null : $this->messages->mailbox($userId, (int)$id);
		if ($mailbox === null || (int)$mailbox->getAccountId() !== (int)$account->getId()) {
			throw new ToolFailure(MailDraftMessages::noDraftsFolder());
		}
		return $mailbox;
	}
}
