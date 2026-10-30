<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Move;

use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * The folder a message is asked to move to, resolved through the user's own accounts on every call and checked the way
 * the Mail app's own move checks it: a folder of the user (another user's folder reads like a missing one), of the
 * message's own account (MailManager::moveMessage() refuses another account), able to hold messages.
 *
 * Its kind tells the plan what the move means: `same` (the message is already there: nothing to move), `junk` (the
 * account's Junk folder: only a move, no spam report and no $junk keyword, since the Mail app reports spam on that
 * keyword, not on a move), `trash` (the account's Trash, where the Mail app's TrashRetentionJob deletes for good what
 * stays longer than the account's retention, so the plan says it with the retention read now) or `folder`.
 */
final class MailMoveDestination {
	/** The message is already in the folder. */
	public const SAME = 'same';

	/** The account's Junk folder. */
	public const JUNK = 'junk';

	/** The account's Trash. */
	public const TRASH = 'trash';

	/** Any other folder of the account. */
	public const FOLDER = 'folder';

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
	 * The destination folder and its kind.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{message:object, mailbox:object, account:object} $found the user's own message, folder and account
	 * @param int $mailboxId folder id asked for
	 * @return array{mailbox:object, kind:string, retentionDays:int|null} the folder, its kind and, for the Trash, the days
	 *     the Mail app keeps messages there (null when it deletes none on its own, and for any other kind)
	 * @throws ToolFailure when the folder is not the user's, of another account or cannot hold messages
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function resolve(string $userId, array $found, int $mailboxId): array {
		$mailbox = $this->messages->mailbox($userId, $mailboxId);
		if ($mailbox === null) {
			throw new ToolFailure(MailMoveMessages::folderNotFound());
		}
		if ((int)$mailbox->getAccountId() !== (int)$found['account']->getId()) {
			throw new ToolFailure(MailMoveMessages::otherAccount());
		}
		if ($mailbox->getSelectable() !== null && !$mailbox->getSelectable()) {
			throw new ToolFailure(MailMoveMessages::notSelectable());
		}
		$settings = $found['account']->getMailAccount();
		$kind = match (true) {
			(int)$mailbox->getId() === (int)$found['mailbox']->getId() => self::SAME,
			(int)$mailbox->getId() === (int)$settings->getTrashMailboxId() => self::TRASH,
			(int)$mailbox->getId() === (int)$settings->getJunkMailboxId() => self::JUNK,
			default => self::FOLDER,
		};
		return ['mailbox' => $mailbox, 'kind' => $kind, 'retentionDays' => $kind === self::TRASH ? self::retention($settings->getTrashRetentionDays()) : null];
	}

	/**
	 * The Trash retention as the Mail app's TrashRetentionJob applies it: none unset or not positive.
	 *
	 * @param mixed $days the stored value
	 * @return int|null the days, or null when the Mail app deletes nothing on its own
	 */
	private static function retention(mixed $days): ?int {
		return $days === null || (int)$days <= 0 ? null : (int)$days;
	}
}
