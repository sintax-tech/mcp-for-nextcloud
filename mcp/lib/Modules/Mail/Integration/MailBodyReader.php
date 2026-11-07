<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailUnavailableException;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Reads the body of one cached message of the user through the Mail app, only when the cache and the server are in
 * the same UID generation and the message fits the size budget.
 *
 * Everything runs on one IMAP client of the Mail app, logged out at the end:
 * 1. the UIDVALIDITY of the folder on the server (STATUS, before the folder is selected) must equal the one of the
 *    folder's cache ({@see MailCacheGeneration}); otherwise the cached UID may name another message, and nothing is
 *    fetched;
 * 2. the metadata preflight ({@see MailBodyPreflight}): its FETCH selects the folder (EXAMINE), then whole message
 *    size and, as a complementary check, the Message-ID;
 * 3. the UIDVALIDITY of the selection that FETCH opened must still equal the cache's: a folder recreated between the
 *    STATUS of step 1 and that EXAMINE opens a new generation, and its message at the cached UID is another one even
 *    with the same Message-ID or none. The client answers this from the state of the selected folder, which the
 *    EXAMINE response set, without another command;
 * 4. `MailManager::getImapMessage()` with the body, on that same selection: the Mail fetcher reads with BODY.PEEK, so
 *    no \Seen flag is set;
 * 5. the Message-ID of the message read must still be the cached one.
 * Within one selection of a folder a UID keeps its message (RFC 3501 §2.3.1.1). A reconnection of the client between
 * steps 3 and 4 remains an undemonstrated limit, checked only by the complementary Message-ID of step 5.
 */
class MailBodyReader {
	/**
	 * Receives the Mail classes and the preflight.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailBodyPreflight $preflight size check before the body is read
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailBodyPreflight $preflight = new MailBodyPreflight(),
	) {
	}

	/**
	 * The message with its body, as the Mail app reads it.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $mailbox OCA\Mail\Db\Mailbox of that account, as stored now
	 * @param object $message OCA\Mail\Db\Message of that folder
	 * @return object OCA\Mail\Model\IMAPMessage
	 * @throws ToolFailure when the cache is of another UID generation, the message changed, is gone, is too large or
	 *     the server cannot be read
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function read(string $userId, object $account, object $mailbox, object $message): object {
		$manager = $this->services->mailManager($userId);
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
			if ($this->preflight->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$this->preflight->check($client, $name, $uid, $cachedId);
			if ($this->preflight->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$imap = $manager->getImapMessage($client, $account, $mailbox, $uid, true);
			if (!MailBodyPreflight::sameMessage($cachedId, (string)$imap->getMessageId())) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			return $imap;
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			// A server, network or parsing failure of the Mail app: never its message, which can carry mailbox data.
			throw new ToolFailure(MailMessages::bodyUnavailable(), 0, $e);
		} finally {
			try {
				$client?->logout();
			} catch (\Throwable) {
				// The connection may already be gone; nothing to release then.
			}
		}
	}
}
