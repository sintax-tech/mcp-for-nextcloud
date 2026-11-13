<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailSaveMessages;
use OCA\Mcp\Modules\Mail\Extraction\MailAttachmentPart;
use OCA\Mcp\Modules\Mail\Writing\MailSavePreflight;
use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Reads metadata or one binary attachment on the native Mail connection, preserving the existing UIDVALIDITY sequence. */
final class MailSaveReader {
	/**
	 * Receives the Mail classes and the two preflights.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailSavePreflight $preflight metadata check of the message and of the attachment
	 * @param MailBodyPreflight $generation reads the UIDVALIDITY of a folder on the server
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailSavePreflight $preflight,
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
	 * @param string $attachmentId MIME part number, already {@see \OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight::validId()}
	 * @param int $limit Effective decoded byte budget.
	 * @param bool $fetchContent False for metadata-only preview.
	 * @return array{part:MailAttachmentPart, declaredUpperBytes:int, content?:string} metadata and optional decoded bytes
	 * @throws ToolFailure when the cache is of another UID generation, the message changed, is gone, is too large or
	 *     sealed, the attachment does not exist or is too large, or the server cannot be read
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function read(string $userId, object $account, object $mailbox, object $message, string $attachmentId, int $limit, bool $fetchContent): array {
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
			$part = $this->preflight->check($client, $name, $uid, $cachedId, $attachmentId, $limit);
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			if (!$fetchContent) {
				return $part;
			}
			$content = $mapper->getAttachment($client, $name, $uid, $attachmentId, $userId)->getContent();
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			if (!is_string($content)) {
				throw new ToolFailure(MailAttachmentMessages::unavailable());
			}
			if (strlen($content) > $limit) {
				throw new ToolFailure(MailSaveMessages::limit($limit));
			}
			return $part + ['content' => $content];
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
