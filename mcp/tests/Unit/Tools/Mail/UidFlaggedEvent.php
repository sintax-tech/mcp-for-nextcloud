<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCP\EventDispatcher\Event;

/**
 * Test-only shape of the Mail app's flag event up to Mail 5.10.x, which takes the message's UID as an int.
 */
final class UidFlaggedEvent extends Event {
	/**
	 * Keeps what the event carries.
	 *
	 * @param object $account OCA\Mail\Account
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param int $uid message UID
	 * @param string $flag flag name
	 * @param bool $set new state
	 * @return void
	 */
	public function __construct(
		public object $account,
		public object $mailbox,
		public int $uid,
		public string $flag,
		public bool $set,
	) {
		parent::__construct();
	}
}
