<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Move;

use OCA\Mcp\Tools\Mail\MailBodyPreflight;
use OCA\Mcp\Tools\Mail\MailCacheGeneration;
use OCA\Mcp\Tools\Mail\MailMessages;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Moves one cached message to another folder of its account on the mail server, only when the server still holds that
 * message at the cached UID.
 *
 * MailManager::moveMessage() is not used for the move itself: it opens an IMAP client of its own, where no check made
 * here would hold. Everything below runs on one IMAP client of the Mail app, within one selection of the source folder,
 * logged out at the end:
 * 1. the UIDVALIDITY of the source folder on the server (STATUS) must equal the one of its cache
 *    ({@see MailCacheGeneration}); otherwise nothing is selected;
 * 2. the source folder is selected read-write (SELECT), as the move needs, and the UIDVALIDITY of that selection must
 *    still equal the cache's;
 * 3. one FETCH reads the envelope of the UID: the message must exist and keep the cached Message-ID (complementary);
 * 4. the server must have MOVE or UIDPLUS ({@see MailMoveCapability}): without both, Horde's fallback would change the
 *    \Deleted flag of other messages of the folder for a while, so the move is refused;
 * 5. the Mail app's own IMAP MessageMapper::move() moves it, on that same selection (Horde reopens a folder only for
 *    another folder or mode): UID MOVE with MOVE, or UID COPY then the UID EXPUNGE of that UID with UIDPLUS alone.
 * A failure before step 5 leaves the message where it was. A failure during step 5 has no known outcome (the server may
 * have moved it, or copied it and kept the original, before the answer was lost), so it is reported as uncertain: never
 * retried, never undone. After a copy that kept the original, the source and the plan still hold, so confirming again
 * could copy it once more; the answer says to look in both folders and not to repeat.
 */
class MailMoveStore {
	/** Horde_Imap_Client: holds the OPEN_READWRITE constant. */
	private const CLIENT_CLASS = 'Horde_Imap_Client';

	/** Horde_Imap_Client_Fetch_Query: the FETCH items. */
	private const FETCH_QUERY_CLASS = 'Horde_Imap_Client_Fetch_Query';

	/** Horde_Imap_Client_Ids: the UID set of a FETCH. */
	private const IDS_CLASS = 'Horde_Imap_Client_Ids';

	/**
	 * Receives the Mail classes and the generation reader.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailBodyPreflight $generation reads the UIDVALIDITY of a folder on the server
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailBodyPreflight $generation = new MailBodyPreflight(),
	) {
	}

	/**
	 * Moves the message.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $source OCA\Mail\Db\Mailbox of the message, as stored now
	 * @param object $message OCA\Mail\Db\Message of that folder
	 * @param object $destination OCA\Mail\Db\Mailbox of the same account, as stored now
	 * @return int|null the UID the message got in the destination, or null when the server does not tell it
	 * @throws ToolFailure when the cache is of another UID generation, the message changed or is gone, the server could
	 *     not be read or has neither MOVE nor UIDPLUS (nothing moved), or the move has no known outcome
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function move(string $userId, object $account, object $source, object $message, object $destination): ?int {
		$mapper = $this->services->imapMessageMapper($userId);
		$factory = $this->services->clientFactory($userId);
		$cached = MailCacheGeneration::of($source);
		if ($cached === null) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$client = null;
		try {
			$client = $factory->getClient($account);
			$name = (string)$source->getName();
			$uid = (int)$message->getUid();
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$client->openMailbox($name, constant(self::CLIENT_CLASS . '::OPEN_READWRITE'));
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$this->assertSameMessage($client, $name, $uid, $message->getMessageId() === null ? null : (string)$message->getMessageId());
			MailMoveCapability::assertMoveTouchesOnlyTheMessage($client);
		} catch (ToolFailure $e) {
			$this->logout($client);
			throw $e;
		} catch (\Throwable $e) {
			$this->logout($client);
			// A server, network or protocol failure before the move: never its message, which can carry mailbox data.
			throw new ToolFailure(MailMoveMessages::notMoved(), 0, $e);
		}
		try {
			$newUid = $mapper->move($client, $name, $uid, (string)$destination->getName());
			return is_int($newUid) ? $newUid : null;
		} catch (\Throwable $e) {
			throw new ToolFailure(MailMoveMessages::uncertain(), 0, $e);
		} finally {
			$this->logout($client);
		}
	}

	/**
	 * Checks that the server holds the cached message at the UID.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app, with the folder selected
	 * @param string $mailbox folder name
	 * @param int $uid IMAP UID from the cache
	 * @param string|null $messageId Message-ID the cache holds, or null
	 * @return void
	 * @throws ToolFailure when the message is gone or its Message-ID differs
	 */
	private function assertSameMessage(object $client, string $mailbox, int $uid, ?string $messageId): void {
		$queryClass = self::FETCH_QUERY_CLASS;
		$idsClass = self::IDS_CLASS;
		$query = new $queryClass();
		$query->envelope();
		$fetch = $client->fetch($mailbox, $query, ['ids' => new $idsClass([$uid])])->first();
		if ($fetch === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		if (!MailBodyPreflight::sameMessage($messageId, (string)($fetch->getEnvelope()->message_id ?? ''))) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
	}

	/**
	 * The UIDVALIDITY of the folder, with the move wording when the server gives none.
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
			throw new ToolFailure(MailMoveMessages::notMoved(), 0, $e);
		}
	}

	/**
	 * Logs the client out, when there is one.
	 *
	 * @param object|null $client Horde_Imap_Client_Socket of the Mail app
	 * @return void
	 */
	private function logout(?object $client): void {
		try {
			$client?->logout();
		} catch (\Throwable) {
			// The connection may already be gone; nothing to release then.
		}
	}
}
