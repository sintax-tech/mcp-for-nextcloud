<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Modules\Mail\Presentation\MailFlagMessages;
use OCA\Mcp\Modules\Mail\Editing\MailFlagRequest;

use OCA\Mcp\Modules\Mail\Reading\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Locating\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Sets the asked flags of one cached message on the mail server, only when the server still holds that message at the
 * cached UID.
 *
 * MailManager::flagMessage() is not used for the change itself: it opens an IMAP client of its own, where no check made
 * here would hold, so a folder recreated in between could have another message at the cached UID. Everything below runs
 * on one IMAP client of the Mail app, within one selection of the folder, logged out at the end:
 * 1. the UIDVALIDITY of the folder on the server (STATUS) must equal the one of the folder's cache
 *    ({@see MailCacheGeneration}); otherwise nothing is selected;
 * 2. the folder is selected read-write (SELECT), as any flag change needs, and the UIDVALIDITY of that selection must
 *    still equal the cache's;
 * 3. one FETCH reads the envelope and the flags of the UID: the message must exist and keep the cached Message-ID
 *    (complementary, as for a body); its flags give the current state;
 * 4. only the asked flags that differ are stored, with the Mail app's own IMAP MessageMapper::addFlag()/removeFlag()
 *    (STORE +FLAGS / -FLAGS), on that same selection: Horde reopens a folder only for another folder or mode.
 * Within one selection a UID keeps its message (RFC 3501 §2.3.1.1). The SELECT itself, like any write the Mail app does,
 * takes \Recent away from the messages for later sessions; no message flag changes but the asked ones.
 */
class MailFlagStore {
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
	 * Sets the asked flags.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $mailbox OCA\Mail\Db\Mailbox of that account, as stored now
	 * @param object $message OCA\Mail\Db\Message of that folder
	 * @param MailFlagRequest $request the asked state
	 * @return array{before:array<string, bool>, changed:array<string, bool>} the state the server had and the flags changed
	 * @throws ToolFailure when the cache is of another UID generation, the message changed or is gone, or the server
	 *     refused or failed
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function apply(string $userId, object $account, object $mailbox, object $message, MailFlagRequest $request): array {
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
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$client->openMailbox($name, constant(self::CLIENT_CLASS . '::OPEN_READWRITE'));
			if ($this->uidValidity($client, $name) !== $cached) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$before = $this->current($client, $name, $uid, $message->getMessageId() === null ? null : (string)$message->getMessageId());
			$changed = $request->changes($before);
			foreach ($changed as $flag => $set) {
				$set
					? $mapper->addFlag($client, $mailbox, [$uid], MailFlagRequest::FLAGS[$flag])
					: $mapper->removeFlag($client, $mailbox, [$uid], MailFlagRequest::FLAGS[$flag]);
			}
			return ['before' => $before, 'changed' => $changed];
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			// A server, network or protocol failure of the Mail app: never its message, which can carry mailbox data.
			throw new ToolFailure(MailFlagMessages::unavailable(), 0, $e);
		} finally {
			try {
				$client?->logout();
			} catch (\Throwable) {
				// The connection may already be gone; nothing to release then.
			}
		}
	}

	/**
	 * The current state of the two flags on the server, after checking the message is the cached one.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app, with the folder selected
	 * @param string $mailbox folder name
	 * @param int $uid IMAP UID from the cache
	 * @param string|null $messageId Message-ID the cache holds, or null
	 * @return array<string, bool> state by flag name
	 * @throws ToolFailure when the message is gone or its Message-ID differs
	 */
	private function current(object $client, string $mailbox, int $uid, ?string $messageId): array {
		$queryClass = self::FETCH_QUERY_CLASS;
		$idsClass = self::IDS_CLASS;
		$query = new $queryClass();
		$query->envelope();
		$query->flags();
		$fetch = $client->fetch($mailbox, $query, ['ids' => new $idsClass([$uid])])->first();
		if ($fetch === null) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		if (!MailBodyPreflight::sameMessage($messageId, (string)($fetch->getEnvelope()->message_id ?? ''))) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$flags = array_map(static fn (mixed $flag): string => strtolower((string)$flag), (array)$fetch->getFlags());
		return array_map(static fn (string $imap): bool => in_array($imap, $flags, true), MailFlagRequest::FLAGS);
	}

	/**
	 * The UIDVALIDITY of the folder, with the flag wording when the server gives none.
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
			throw new ToolFailure(MailFlagMessages::unavailable(), 0, $e);
		}
	}
}
