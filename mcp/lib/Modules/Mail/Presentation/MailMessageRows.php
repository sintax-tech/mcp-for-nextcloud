<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Modules\Mail\MailLinks;

/**
 * Shapes the message rows of the message tools from the cache of the Mail app. Subjects, names, addresses and the
 * cached preview are written by whoever sent the message: they go through {@see MailRows::text()} and travel as
 * values inside an answer that says they are untrusted, never as text the server speaks in.
 */
final class MailMessageRows {
	/** Most addresses listed per address field; the count says how many there were. */
	public const MAX_ADDRESSES = 50;

	/**
	 * Receives the time zone dates are shown in and the Mail app links.
	 *
	 * @param DateTimeZone $zone time zone of the user
	 * @param MailLinks $links direct links into the Mail app
	 * @return void
	 */
	public function __construct(
		private DateTimeZone $zone,
		private MailLinks $links,
	) {
	}

	/**
	 * The link that opens a folder in the Mail app.
	 *
	 * @param int $mailboxId folder id
	 * @return string absolute URL
	 */
	public function mailboxUrl(int $mailboxId): string {
		return $this->links->mailbox($mailboxId);
	}

	/**
	 * A message row as the listing, the search and the thread answer with: no body, only what the cache holds.
	 *
	 * @param object $message OCA\Mail\Db\Message
	 * @param int $accountId account of the message's folder
	 * @return array<string, mixed> the row
	 */
	public function summary(object $message, int $accountId): array {
		$preview = $message->getPreviewText();
		return [
			'id' => (int)$message->getId(),
			'accountId' => $accountId,
			'mailboxId' => (int)$message->getMailboxId(),
			'subject' => MailRows::text((string)$message->getSubject()),
			'from' => $this->addresses($message->getFrom()),
			'to' => $this->addresses($message->getTo()),
			'cc' => $this->addresses($message->getCc()),
			'date' => $this->date((int)$message->getSentAt()),
			'unread' => $message->getFlagSeen() === null ? null : !$message->getFlagSeen(),
			'flagged' => $message->getFlagFlagged(),
			'answered' => $message->getFlagAnswered(),
			'hasAttachments' => $message->getFlagAttachments(),
			'preview' => $preview === null || $preview === '' ? null : MailRows::text((string)$preview),
			'url' => $this->links->message($message),
		];
	}

	/**
	 * A date in the user's time zone, ISO 8601 with its offset.
	 *
	 * @param int $timestamp Unix time
	 * @return string the date
	 */
	public function date(int $timestamp): string {
		return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone)->format(DATE_ATOM);
	}

	/**
	 * The addresses of one field, cleaned and bounded.
	 *
	 * @param object $list OCA\Mail\AddressList
	 * @return array{count:int, addresses:list<array{name:string|null, email:string|null}>} the field
	 */
	public function addresses(object $list): array {
		$out = [];
		$count = 0;
		foreach ($list->iterate() as $address) {
			$count++;
			if (count($out) < self::MAX_ADDRESSES) {
				$label = $address->getLabel();
				$email = $address->getEmail();
				$out[] = [
					'name' => $label === null || $label === '' ? null : MailRows::text($label),
					'email' => $email === null || $email === '' ? null : MailRows::text($email),
				];
			}
		}
		return ['count' => $count, 'addresses' => $out];
	}
}
