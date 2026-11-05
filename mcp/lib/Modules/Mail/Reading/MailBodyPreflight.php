<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Reading;

use OCA\Mcp\Modules\Mail\Integration\MailBodyReader;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Size check of a message before its body is read, from metadata only.
 *
 * No Mail API tells the size of a message before loading it, and the sizes of its parts are no safe measure: the
 * Horde parser turns the octets of a base64 part into an estimate of the decoded size, and lets a Content-Disposition
 * `size` parameter written by the sender replace them, while the Mail fetcher also reads the headers of the message
 * and of its parts. So, on the IMAP client of the Mail app (its own login, cache and rate limit), one FETCH asks for
 * the envelope and RFC822.SIZE of the message, nothing of its content, in a folder the client opens read-only (EXAMINE)
 * when it is not open yet, and the whole message size is the budget: headers, every part and the attachments the Mail
 * fetcher would not even load. A size that is missing or not a positive integer is refused.
 *
 * The size is what the server declares. It is a preflight, not a hard cap: a server that declares less than it sends
 * would still make the Mail fetcher, which takes no length limit, receive the larger content.
 */
class MailBodyPreflight {
	/**
	 * Largest message, in octets as the server declares them, whose body is read: 1 MiB, the size of the largest inline
	 * content MCP accepts elsewhere ({@see \OCA\Mcp\Tools\Files\FileCreation::MAX_INLINE_BYTES}).
	 */
	public const MAX_MESSAGE_BYTES = 1048576;

	/** Horde_Imap_Client: holds the STATUS_UIDVALIDITY constant. */
	private const CLIENT_CLASS = 'Horde_Imap_Client';

	/** Horde_Imap_Client_Fetch_Query: the FETCH items. */
	private const FETCH_QUERY_CLASS = 'Horde_Imap_Client_Fetch_Query';

	/** Horde_Imap_Client_Ids: the UID set of a FETCH. */
	private const IDS_CLASS = 'Horde_Imap_Client_Ids';

	/**
	 * The UIDVALIDITY of a folder: read with STATUS while the folder is not selected, and from the state of the
	 * selection (set by the SELECT or EXAMINE response) once the client has opened it.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app
	 * @param string $mailbox folder name
	 * @return string the UIDVALIDITY, digits without leading zeros
	 * @throws ToolFailure when the server gives none
	 */
	public function uidValidity(object $client, string $mailbox): string {
		$status = $client->status($mailbox, constant(self::CLIENT_CLASS . '::STATUS_UIDVALIDITY'));
		$value = is_array($status) ? ($status['uidvalidity'] ?? null) : null;
		$digits = is_int($value) ? (string)$value : (is_string($value) ? $value : '');
		if (!ctype_digit($digits) || ltrim($digits, '0') === '') {
			throw new ToolFailure(MailMessages::bodyUnavailable());
		}
		return ltrim($digits, '0');
	}

	/**
	 * Checks that the whole message fits the budget, without reading any of its content, and that its Message-ID is
	 * the cached one (a complementary check: a Message-ID is written by the sender and can repeat or be missing, so it
	 * proves nothing alone; the UID generation is checked before, by {@see MailBodyReader}).
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app
	 * @param string $mailbox folder name
	 * @param int $uid IMAP UID from the cache
	 * @param string|null $messageId Message-ID the cache holds, or null
	 * @return int the size the server declared
	 * @throws ToolFailure when the message is gone, the size is missing or too large, or the Message-ID differs
	 */
	public function check(object $client, string $mailbox, int $uid, ?string $messageId): int {
		$queryClass = self::FETCH_QUERY_CLASS;
		$idsClass = self::IDS_CLASS;
		$query = new $queryClass();
		$query->envelope();
		$query->size();
		$fetch = $client->fetch($mailbox, $query, ['ids' => new $idsClass([$uid])])->first();
		if ($fetch === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		$size = $fetch->getSize();
		if (!is_int($size) || $size <= 0) {
			throw new ToolFailure(MailMessages::bodyUnavailable());
		}
		if (!self::sameMessage($messageId, (string)($fetch->getEnvelope()->message_id ?? ''))) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		if ($size > self::MAX_MESSAGE_BYTES) {
			throw new ToolFailure(MailMessages::bodyTooLarge($size, self::MAX_MESSAGE_BYTES));
		}
		return $size;
	}

	/**
	 * Whether the Message-ID the server gives is the cached one. Equal Message-IDs do not make two messages the same
	 * one; different ones do make them different.
	 *
	 * @param string|null $cached Message-ID of the cache
	 * @param string $server Message-ID of the envelope
	 * @return bool true when they are equal once trimmed of blanks and angle brackets
	 */
	public static function sameMessage(?string $cached, string $server): bool {
		return self::normal((string)$cached) === self::normal($server);
	}

	/**
	 * A Message-ID without surrounding blanks and angle brackets.
	 *
	 * @param string $id raw Message-ID
	 * @return string the bare id
	 */
	private static function normal(string $id): string {
		return trim(trim($id), '<>');
	}
}
