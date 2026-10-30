<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * The UIDVALIDITY the cached messages of a folder belong to, read from the folder's own synchronization tokens.
 *
 * A UID names one message only within one UIDVALIDITY (RFC 3501 §2.3.1.1): after a folder is recreated or restored the
 * server can give the same UID to another message, and the Mail cache still holds the old one until it synchronizes.
 * The Mail app stores, per folder, the sync tokens of its IMAP client (`Mailbox::getSyncNewToken()`, `...Changed...`,
 * `...Vanished...`), each written by `Horde_Imap_Client_Base::getSyncToken()`: base64 of comma-separated status
 * values, `V<uidvalidity>` among them. The Mail synchronizer hands them back to `Horde_Imap_Client_Base::sync()`, which
 * refuses with UIDVALIDITY_CHANGED when that `V` differs from the server's; this class reads the same `V` without
 * syncing, so a body is read only when the cache and the server are in the same UID generation.
 *
 * A folder without the three tokens (never synchronized, or reset by the Mail app), with an unreadable token, or with
 * tokens of different generations has no verifiable generation: null, and the body is not read.
 */
final class MailCacheGeneration {
	/** Key of the UIDVALIDITY in a Horde sync token (Horde_Imap_Client_Data_Sync::$map). */
	public const UIDVALIDITY_KEY = 'V';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The UIDVALIDITY of the cache of a folder.
	 *
	 * @param object $mailbox OCA\Mail\Db\Mailbox, as stored now
	 * @return string|null the UIDVALIDITY all three tokens agree on, or null when there is none to trust
	 */
	public static function of(object $mailbox): ?string {
		$values = [];
		foreach ([$mailbox->getSyncNewToken(), $mailbox->getSyncChangedToken(), $mailbox->getSyncVanishedToken()] as $token) {
			$value = is_string($token) ? self::fromToken($token) : null;
			if ($value === null) {
				return null;
			}
			$values[$value] = true;
		}
		return count($values) === 1 ? (string)array_key_first($values) : null;
	}

	/**
	 * The UIDVALIDITY of one Horde sync token.
	 *
	 * @param string $token base64 of "U<uidnext>,V<uidvalidity>,..."
	 * @return string|null the UIDVALIDITY, digits only, or null when the token is not one
	 */
	public static function fromToken(string $token): ?string {
		$decoded = base64_decode($token, true);
		if ($decoded === false || $decoded === '') {
			return null;
		}
		foreach (explode(',', $decoded) as $item) {
			if (str_starts_with($item, self::UIDVALIDITY_KEY)) {
				$value = substr($item, strlen(self::UIDVALIDITY_KEY));
				return ctype_digit($value) && $value !== '0' ? ltrim($value, '0') : null;
			}
		}
		return null;
	}
}
