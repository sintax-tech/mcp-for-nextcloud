<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCA\Mcp\Tools\ToolFailure;

/**
 * Check of a message and of one of its attachments before any content is read, from metadata only.
 *
 * One FETCH on the Mail app's IMAP client asks for the envelope, RFC822.SIZE and BODYSTRUCTURE of the message: no
 * content, in a folder the client opens read-only (EXAMINE) when it is not open yet. Then:
 * - the whole message size is the budget, as for a body ({@see MailBodyPreflight::MAX_MESSAGE_BYTES}, 1 MiB): an
 *   attachment is never larger than its message, and the declared part sizes are no safe measure;
 * - the Message-ID must be the cached one (complementary, as in {@see MailBodyPreflight::check()});
 * - an encrypted message (PGP/MIME `multipart/encrypted`) or an S/MIME container (`application/pkcs7-mime`, enveloped
 *   or opaque signed) is refused before the Mail app would decrypt or unwrap it;
 * - the part must exist, hold content itself (no multipart, no embedded message), sit only under multipart parts the
 *   Mail fetcher walks into, and be an attachment the fetcher lists ({@see MailFetcherParts}).
 */
class MailAttachmentPreflight {
	/** Most characters of an attachment id. */
	public const MAX_ID_LENGTH = 64;

	/** Pattern of an attachment id: a MIME part number, never 0 (the header), at most 16 levels deep. */
	private const ID_PATTERN = '^[1-9][0-9]{0,3}(\\.[1-9][0-9]{0,3}){0,15}$';

	/** Horde_Imap_Client_Fetch_Query: the FETCH items. */
	private const FETCH_QUERY_CLASS = 'Horde_Imap_Client_Fetch_Query';

	/** Horde_Imap_Client_Ids: the UID set of a FETCH. */
	private const IDS_CLASS = 'Horde_Imap_Client_Ids';

	/** Top-level types of an encrypted or opaque signed message, whose parts only exist once the Mail app unwraps it. */
	private const SEALED_TYPES = ['multipart/encrypted', 'application/pkcs7-mime', 'application/x-pkcs7-mime'];

	/**
	 * Whether an attachment id has the shape of a MIME part number.
	 *
	 * @param string $id the id the caller sent
	 * @return bool true for "1", "2", "2.1" and the like
	 */
	public static function validId(string $id): bool {
		return strlen($id) <= self::MAX_ID_LENGTH && preg_match('/' . self::ID_PATTERN . '/D', $id) === 1;
	}

	/**
	 * Checks the message and the attachment without reading any content.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app
	 * @param string $mailbox folder name
	 * @param int $uid IMAP UID from the cache
	 * @param string|null $messageId Message-ID the cache holds, or null
	 * @param string $attachmentId MIME part number, already {@see self::validId()}
	 * @return MailAttachmentPart what the structure declares about the attachment
	 * @throws ToolFailure when the message is gone, changed, too large, sealed, or has no such attachment
	 */
	public function check(object $client, string $mailbox, int $uid, ?string $messageId, string $attachmentId): MailAttachmentPart {
		$queryClass = self::FETCH_QUERY_CLASS;
		$idsClass = self::IDS_CLASS;
		$query = new $queryClass();
		$query->envelope();
		$query->size();
		$query->structure();
		$fetch = $client->fetch($mailbox, $query, ['ids' => new $idsClass([$uid])])->first();
		if ($fetch === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		$size = $fetch->getSize();
		if (!is_int($size) || $size <= 0) {
			throw new ToolFailure(MailAttachmentMessages::unavailable());
		}
		if (!MailBodyPreflight::sameMessage($messageId, (string)($fetch->getEnvelope()->message_id ?? ''))) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		if ($size > MailBodyPreflight::MAX_MESSAGE_BYTES) {
			throw new ToolFailure(MailAttachmentMessages::messageTooLarge($size, MailBodyPreflight::MAX_MESSAGE_BYTES));
		}
		$structure = $fetch->getStructure();
		if (!is_object($structure)) {
			throw new ToolFailure(MailAttachmentMessages::unavailable());
		}
		if (in_array(strtolower((string)$structure->getType()), self::SEALED_TYPES, true)) {
			throw new ToolFailure(MailAttachmentMessages::encrypted());
		}
		return $this->attachment($structure, $attachmentId);
	}

	/**
	 * The attachment the id names, when the Mail fetcher reaches and lists it ({@see MailFetcherParts}).
	 *
	 * @param object $structure Horde_Mime_Part of the whole message
	 * @param string $id MIME part number
	 * @return MailAttachmentPart the declared description of the part
	 * @throws ToolFailure when the id names no attachment the fetcher lists, or an embedded message
	 */
	private function attachment(object $structure, string $id): MailAttachmentPart {
		$segments = explode('.', $id);
		for ($depth = 1; $depth < count($segments); $depth++) {
			$ancestor = $structure->getPart(implode('.', array_slice($segments, 0, $depth)));
			if (!is_object($ancestor) || !MailFetcherParts::entered($ancestor)) {
				throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
			}
		}
		$part = $structure->getPart($id);
		if (!is_object($part) || strtolower((string)$part->getPrimaryType()) === 'multipart' || !MailFetcherParts::listed($part)) {
			throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
		}
		$type = strtolower((string)$part->getType());
		if ($type === 'message/rfc822') {
			// Listed by the Mail fetcher as an attachment, but it is a whole message with its own parts, not a file.
			throw new ToolFailure(MailAttachmentMessages::unsupportedType());
		}
		$name = MailFetcherParts::name($part);
		$charset = $part->getCharset();
		return new MailAttachmentPart($id, $type, is_string($charset) && $charset !== '' ? strtolower($charset) : null, $name === '' ? null : $name);
	}
}
