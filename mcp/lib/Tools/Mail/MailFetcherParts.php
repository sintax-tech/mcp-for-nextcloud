<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * Which parts of a message the Mail app lists as attachments, by the rules of its fetcher
 * (OCA\Mail\IMAP\ImapMessageFetcher::getPart() of Mail 5.10.11, the minimum of the attachment tool), on the structure
 * Horde builds (Horde_Mime_Part). The fetcher walks the parts of a multipart message one by one and, for each part:
 * - a named text/calendar part is listed (and the walk stops there unless it is an invitation);
 * - a part Horde calls an attachment, or an embedded message/rfc822, is listed, signatures excepted, and not entered;
 * - a part with a file name (or a Content-ID, which counts for neither text nor multipart parts) is an inline
 *   attachment, not listed and not entered;
 * - only a multipart part that is none of these is entered, and its parts are walked the same way.
 * So a part is reachable only when every part above it, below the root, is a multipart the fetcher enters.
 */
final class MailFetcherParts {
	/** Signature parts, which the fetcher never lists as attachments. */
	private const SIGNATURE_TYPES = ['application/pgp-signature', 'application/pkcs7-signature', 'application/x-pkcs7-signature'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Whether the fetcher walks into the parts of this part.
	 *
	 * @param object $part Horde_Mime_Part above the requested one, below the root
	 * @return bool true for a multipart that is neither an attachment nor named
	 */
	public static function entered(object $part): bool {
		return strtolower((string)$part->getPrimaryType()) === 'multipart' && $part->isAttachment() !== true && self::name($part) === null;
	}

	/**
	 * Whether the fetcher lists this part as an attachment.
	 *
	 * @param object $part Horde_Mime_Part the requested one
	 * @return bool true for a named text/calendar part, or a part Horde calls an attachment or a message/rfc822, signatures
	 *     excepted
	 */
	public static function listed(object $part): bool {
		$type = strtolower((string)$part->getType());
		if ($type === 'text/calendar' && self::name($part) !== null) {
			return true;
		}
		return ($part->isAttachment() === true || $type === 'message/rfc822') && !in_array($type, self::SIGNATURE_TYPES, true);
	}

	/**
	 * The declared file name of a part, as the fetcher tests it: an empty name is still a name.
	 *
	 * @param object $part Horde_Mime_Part
	 * @return string|null the name, or null when it has none
	 */
	public static function name(object $part): ?string {
		$name = $part->getName();
		return is_string($name) ? $name : null;
	}
}
