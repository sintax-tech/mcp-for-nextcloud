<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Writing;

use OCA\Mcp\Modules\Mail\Reading\{MailBodyPreflight, MailAttachmentPreflight};
use OCA\Mcp\Modules\Mail\Presentation\{MailSaveMessages, MailMessages, MailAttachmentMessages};
use OCA\Mcp\Modules\Mail\Extraction\MailAttachmentPart;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Metadata-only preflight. Horde already multiplies BODYSTRUCTURE base64 octets by .75; never do it twice. */
final class MailSavePreflight {
	/** Maximum declared whole-message octets; this is separate from the decoded part limit. */
	public const MAX_MESSAGE_BYTES = 128 * 1024 * 1024;
	/** Maximum MIME nodes examined, including root. */
	public const MAX_PARTS = 512;
	/** Maximum MIME depth, including root. */
	public const MAX_DEPTH = 16;
	/**
	 * @param object $client Native Mail IMAP client.
	 * @param string $mailbox Folder name.
	 * @param int $uid Message UID.
	 * @param string|null $messageId Cached Message-ID.
	 * @param string $id Part ID.
	 * @param int $limit Effective decoded budget.
	 * @return array{part:MailAttachmentPart,declaredUpperBytes:int} Authorized part and safe decoded upper bound.
	 */
	public function check(object $client, string $mailbox, int $uid, ?string $messageId, string $id, int $limit): array {
		$queryClass = 'Horde_Imap_Client_Fetch_Query';
		$idsClass = 'Horde_Imap_Client_Ids';
		$query = new $queryClass();
		$query->envelope();
		$query->size();
		$query->structure();
		$fetch = $client->fetch($mailbox, $query, ['ids' => new $idsClass([$uid])])->first();
		if ($fetch === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		if (!MailBodyPreflight::sameMessage($messageId, (string)($fetch->getEnvelope()->message_id ?? ''))) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$size = $fetch->getSize();
		if (!is_int($size) || $size <= 0 || $size > self::MAX_MESSAGE_BYTES) {
			throw new ToolFailure(MailSaveMessages::metadata());
		}
		$structure = $fetch->getStructure();
		if (!is_object($structure)) {
			throw new ToolFailure(MailSaveMessages::metadata());
		}
		$count = 0;
		$this->bounded($structure, 1, $count);
		$part = (new MailAttachmentPreflight())->attachment($structure, $id);
		$native = $structure->getPart($id);
		// Horde Socket::_parseBodystructure stores floor(encoded * .75) for base64, encoded size for other encodings.
		// Add one for that truncation. QP, 7bit, 8bit and binary decoding never increases the octet count.
		$bytes = $native->getBytes();
		if (!is_int($bytes) || $bytes <= 0 || $bytes >= PHP_INT_MAX) {
			throw new ToolFailure(MailSaveMessages::metadata());
		}
		$upper = $bytes + 1;
		if ($limit <= 0 || $upper > $limit) {
			throw new ToolFailure(MailSaveMessages::limit($limit));
		}
		return ['part' => $part, 'declaredUpperBytes' => $upper];
	}

	/**
	 * @param object $part MIME node.
	 * @param int $depth Current depth.
	 * @param int $count Shared node budget.
	 * @return void
	 * @throws ToolFailure When metadata is too large or sealed.
	 */
	private function bounded(object $part, int $depth, int &$count): void {
		if (++$count > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
			throw new ToolFailure(MailSaveMessages::metadata());
		}
		if (in_array(strtolower((string)$part->getType()), ['multipart/encrypted', 'application/pkcs7-mime', 'application/x-pkcs7-mime'], true)) {
			throw new ToolFailure(MailAttachmentMessages::encrypted());
		}
		foreach ($part->getParts() as $child) {
			$this->bounded($child, $depth + 1, $count);
		}
	}
}
