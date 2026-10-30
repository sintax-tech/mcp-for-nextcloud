<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mail\Events;

use OCP\EventDispatcher\Event;

/**
 * Test-only shape of the Mail app's delete event, the same in every supported release: the account, the folder and the
 * UID (typed Account and Mailbox there; untyped here, since the tests hand doubles). Loaded explicitly by the tests that
 * need it, never by the autoloader.
 */
class MessageDeletedEvent extends Event {
	/**
	 * Keeps what the event carries.
	 *
	 * @param object $account OCA\Mail\Account
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param int $uid message UID
	 * @return void
	 */
	public function __construct(
		public object $account,
		public object $mailbox,
		public int $uid,
	) {
		parent::__construct();
	}
}
