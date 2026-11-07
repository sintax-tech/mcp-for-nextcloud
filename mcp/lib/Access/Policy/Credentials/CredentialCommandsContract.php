<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public CredentialCommands contract inherited by the historical policy facade. */
interface CredentialCommandsContract {
	/**
	 * One OAuth unit of $uid (contracts §2.1.1, §3.1): createCode, exchange, refresh, anti-replay. The user's
	 * generations and recheck causes are read before $prepare, which does every possibly remote read (account,
	 * policy) outside any transaction; under the mutex both must still be equal, or the attempt is rolled back and
	 * replayed (at most twice, then PolicyUnavailable, which OAuth answers with temporarily_unavailable). $apply runs
	 * the local statements of the unit with the state row and the generations read under the mutex.
	 *
	 * With $classify, the unit is an issuance (contracts §3.3.4): the pending causes read before the external phase
	 * are classified there and, under the mutex, consumed (with their cut) before $apply, which therefore sees the
	 * generations after that cut. A credential issued by $apply is above the cut, and no After, retry or job of a
	 * consumed cause finds it again; a cause recorded between the phases changes the set and replays the attempt.
	 *
	 * @param string $uid owner of the credential
	 * @param Closure(array):mixed $prepare external phase; its result is passed to $apply
	 * @param Closure(mixed, array{revision:string, lock_seq:string, auth_epoch:string}, array{issue_seq:string, revoked_below:string}):bool $apply
	 *     local phase; returns whether policy changed (only consent's connected flag does)
	 * @param (Closure(string, list<array>): array<string, bool>)|null $classify CauseClassifier::classify(), for an
	 *     issuance; null for a unit that issues nothing
	 * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
	 * @throws PolicyUnavailable when the storage fails, the snapshot keeps changing, a cause cannot be classified, or
	 *     the outcome is uncertain
	 */
	public function runCredential(string $uid, Closure $prepare, Closure $apply, ?Closure $classify = null): array;

	/**
	 * Revokes every OAuth credential of a user, now and before (contracts §3.1, cut point (i)): disable or delete of
	 * the account, or an administrator revoking all of a user's connections. The account may already be gone; its
	 * generation row stays as a tombstone, so a recreated account never inherits the old credentials.
	 *
	 * @param string $uid Nextcloud user id, existing or not
	 * @throws \InvalidArgumentException for an invalid user id
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function revokeCredentials(string $uid): void;

	/**
	 * Revocation observed in use (contracts §3.1, cut point (iii)): the owner was found unusable in a validation, so
	 * every generation up to $observed, the issue_seq read before that validation, is revoked. The cut is fixed and
	 * never raised: a credential issued after $observed is not touched here and is judged on its own use.
	 *
	 * @param string $uid owner of the credential
	 * @param string $observed canonical issue_seq read before the external phase
	 * @throws \InvalidArgumentException for an invalid user id or generation
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function revokeObserved(string $uid, string $observed): void;
}
