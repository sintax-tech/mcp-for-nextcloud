<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Flag;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Tells the Mail app that a flag changed on the server, the way its own MailManager::flagMessage() does right after the
 * IMAP STORE: by dispatching OCA\Mail\Events\MessageFlaggedEvent. The Mail app's MessageCacheUpdaterListener then writes
 * the flag into its message cache, so its views and these tools agree; its spam, ham and junk listeners act only on the
 * $junk and $notjunk keywords, which this tool never sets.
 *
 * The event takes the message's UID up to Mail 5.10.x and the cached message itself from Mail 5.11.0 on (third
 * constructor parameter `int $uid`, then `Message $message`), so the shape is read from the installed class and both are
 * built exactly as that release's flagMessage() builds them (mail-flag-event.json checks the two shapes).
 */
class MailFlagEvent {
	/**
	 * Receives Nextcloud's event dispatcher.
	 *
	 * @param IEventDispatcher $events event dispatcher
	 * @return void
	 */
	public function __construct(
		private IEventDispatcher $events,
	) {
	}

	/**
	 * Dispatches the Mail app's flag event for one changed flag.
	 *
	 * @param string $eventClass OCA\Mail\Events\MessageFlaggedEvent, resolved by name
	 * @param object $account OCA\Mail\Account
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param object $message OCA\Mail\Db\Message, the cached message the flag changed on
	 * @param string $flag seen or flagged, as the Mail app names it
	 * @param bool $set the new state
	 * @return void
	 * @throws \ReflectionException when the class does not exist
	 */
	public function dispatch(string $eventClass, object $account, object $mailbox, object $message, string $flag, bool $set): void {
		$this->events->dispatchTyped(new $eventClass($account, $mailbox, self::takesUid($eventClass) ? (int)$message->getUid() : $message, $flag, $set));
	}

	/**
	 * Whether the installed event takes the UID (Mail up to 5.10.x) rather than the message.
	 *
	 * @param string $eventClass the event class
	 * @return bool true when its third constructor parameter is an int
	 * @throws \ReflectionException when the class does not exist
	 */
	public static function takesUid(string $eventClass): bool {
		$parameter = (new \ReflectionMethod($eventClass, '__construct'))->getParameters()[2] ?? null;
		$type = $parameter?->getType();
		return $type instanceof \ReflectionNamedType && $type->getName() === 'int';
	}
}
