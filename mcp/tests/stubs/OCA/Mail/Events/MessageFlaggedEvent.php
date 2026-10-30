<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mail\Events;

use OCP\EventDispatcher\Event;

/**
 * Test-only shape of the Mail app's flag event from Mail 5.11.0 on, which takes the cached message (typed `Message`
 * there; untyped here, since the tests hand a double). Loaded explicitly by the tests that need it, never by the
 * autoloader. The shape of Mail up to 5.10.x, with the UID, is {@see \OCA\Mcp\Tests\Unit\Tools\Mail\UidFlaggedEvent}.
 */
class MessageFlaggedEvent extends Event {
	/**
	 * Keeps what the event carries.
	 *
	 * @param object $account OCA\Mail\Account
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param object $message OCA\Mail\Db\Message
	 * @param string $flag flag name
	 * @param bool $set new state
	 * @return void
	 */
	public function __construct(
		public object $account,
		public object $mailbox,
		public object $message,
		public string $flag,
		public bool $set,
	) {
		parent::__construct();
	}
}
