<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Extraction\MailAttachmentPart;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight;
use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailUnavailableException;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Reads the decoded content of one attachment of a cached message of the user through the Mail app, only when the
 * cache and the server are in the same UID generation, the message fits the size budget and the part is an attachment.
 *
 * Everything runs on one IMAP client of the Mail app, logged out at the end, in the order of {@see MailBodyReader}:
 * 1. the UIDVALIDITY of the folder on the server (STATUS, before the folder is selected) must equal the one of the
 *    folder's cache ({@see MailCacheGeneration}); otherwise nothing is fetched;
 * 2. the metadata preflight ({@see MailAttachmentPreflight}): its FETCH selects the folder (EXAMINE), then whole
 *    message size, complementary Message-ID, sealed message and the attachment itself, from the structure;
 * 3. the UIDVALIDITY of the selection that FETCH opened must still equal the cache's, answered from the state the
 *    EXAMINE response set, without another command;
 * 4. `OCA\Mail\IMAP\MessageMapper::getAttachment()` on that same client and selection. MailManager::getMailAttachment()
 *    is not used: it opens a client of its own, where neither check above would hold. From Mail 5.10.11 on
 *    ({@see MailAttachmentSupport}) the mapper fetches the part and its MIME header with BODY.PEEK, so no \Seen flag is
 *    set;
 * 5. the decoded content must be within {@see self::MAX_ATTACHMENT_BYTES} before anything parses it.
 * Within one selection of a folder a UID keeps its message (RFC 3501 §2.3.1.1). A reconnection of the client between
 * steps 3 and 4 remains an undemonstrated limit, as for the body.
 */
class MailAttachmentReader {
	/** Largest decoded attachment parsed: the message budget, since a part is never larger than its message. */
	public const MAX_ATTACHMENT_BYTES = MailBodyPreflight::MAX_MESSAGE_BYTES;

	/**
	 * Receives the Mail classes and the two preflights.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailAttachmentPreflight $preflight metadata check of the message and of the attachment
	 * @param MailBodyPreflight $generation reads the UIDVALIDITY of a folder on the server
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailAttachmentPreflight $preflight = new MailAttachmentPreflight(),
		private MailBodyPreflight $generation = new MailBodyPreflight(),
	) {
	}

	/**
	 * The declared description and the decoded content of the attachment, as the Mail app reads it.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $mailbox OCA\Mail\Db\Mailbox of that account, as stored now
	 * @param object $message OCA\Mail\Db\Message of that folder
	 * @param string $attachmentId MIME part number, already {@see MailAttachmentPreflight::validId()}
	 * @return array{part:MailAttachmentPart, content:string} the attachment
	 * @throws ToolFailure when the cache is of another UID generation, the message changed, is gone, is too large or
	 *     sealed, the attachment does not exist or is too large, or the server cannot be read
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function read(string $userId, object $account, object $mailbox, object $message, string $attachmentId): array {
		$mapper = $this->services->imapMessageMapper($userId);
		$factory = $this->services->clientFactory($userId);
		$cached = MailCacheGeneration::of($mailbox);
		if ($cached === null) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$client = null;
		try {
			$client = $factory->getClient($account);
			$name = (string)$mailbox->getName();
			$uid = (int)$message->getUid();
			$cachedId = $message->getMessageId() === null ? null : (string)$message->getMessageId();
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$part = $this->preflight->check($client, $name, $uid, $cachedId, $attachmentId);
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$content = $mapper->getAttachment($client, $name, $uid, $attachmentId, $userId)->getContent();
			if (!is_string($content)) {
				throw new ToolFailure(MailAttachmentMessages::unavailable());
			}
			if (strlen($content) > self::MAX_ATTACHMENT_BYTES) {
				throw new ToolFailure(MailAttachmentMessages::attachmentTooLarge(self::MAX_ATTACHMENT_BYTES));
			}
			return ['part' => $part, 'content' => $content];
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			// A server, network or parsing failure of the Mail app: never its message, which can carry mailbox data.
			throw new ToolFailure(MailAttachmentMessages::unavailable(), 0, $e);
		} finally {
			try {
				$client?->logout();
			} catch (\Throwable) {
				// The connection may already be gone; nothing to release then.
			}
		}
	}

	/**
	 * The UIDVALIDITY of the folder, with the attachment wording when the server gives none.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app
	 * @param string $mailbox folder name
	 * @return string the UIDVALIDITY, digits without leading zeros
	 * @throws ToolFailure when the server gives none
	 */
	private function uidValidity(object $client, string $mailbox): string {
		try {
			return $this->generation->uidValidity($client, $mailbox);
		} catch (ToolFailure $e) {
			throw new ToolFailure(MailAttachmentMessages::unavailable(), 0, $e);
		}
	}
}
