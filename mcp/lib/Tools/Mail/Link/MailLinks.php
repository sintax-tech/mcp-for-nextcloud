<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Link;

use OCP\IURLGenerator;

/**
 * Direct links into the Nextcloud Mail app, built by Nextcloud's own URL generator from the Mail app's own web routes
 * (appinfo/routes.php, the same in every release from 5.0.0 to 5.12.5):
 * - a folder opens with `mail.page.mailbox` (`/box/{id}`), where id is the folder id of the folder cache;
 * - a message opens with `mail.page.thread` (`/box/{mailboxId}/thread/{id}`). The Mail front end reads that id as the
 *   database id of the cached message (envelope `databaseId`) and loads its conversation from
 *   `api/messages/{id}/thread`, which answers only for a message with a thread root: a message without one has no
 *   link, rather than a link that opens an error.
 * Accounts have no web route in the Mail app, so they get no link. A link adds no access: the Mail app checks the
 * user's own account again when it is opened, and opening a message there marks it read as the Mail app always does.
 */
final class MailLinks {
	/** Route of a folder in the Mail app. */
	public const MAILBOX_ROUTE = 'mail.page.mailbox';

	/** Route of a message and its conversation in the Mail app. */
	public const THREAD_ROUTE = 'mail.page.thread';

	/**
	 * Receives the URL generator.
	 *
	 * @param IURLGenerator $urls Nextcloud's URL generator
	 * @return void
	 */
	public function __construct(
		private IURLGenerator $urls,
	) {
	}

	/**
	 * The link that opens a folder in the Mail app.
	 *
	 * @param int $mailboxId folder id of the Mail folder cache
	 * @return string absolute URL
	 */
	public function mailbox(int $mailboxId): string {
		return $this->urls->linkToRouteAbsolute(self::MAILBOX_ROUTE, ['id' => $mailboxId]);
	}

	/**
	 * The link that opens a message and its conversation in the Mail app.
	 *
	 * @param object $message OCA\Mail\Db\Message of the user's own folder
	 * @return string|null absolute URL, or null for a message the Mail app has not threaded
	 */
	public function message(object $message): ?string {
		$root = $message->getThreadRootId();
		if ($root === null || $root === '') {
			return null;
		}
		return $this->urls->linkToRouteAbsolute(self::THREAD_ROUTE, ['mailboxId' => (int)$message->getMailboxId(), 'id' => (int)$message->getId()]);
	}
}
