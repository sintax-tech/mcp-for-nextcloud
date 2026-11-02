<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use Closure;
use OCA\Mcp\Access\Policy\{PolicyConflict, PolicyOutbox, PolicyUnavailable, SnapshotInvalidated};

/** Public execution contract shared by the facade and its transaction entry points. */
interface PolicyExecutionContract {
	/**
	 * Read admission shares the abandoned-context gate.
	 *
	 * @throws PolicyUnavailable When the writer was abandoned.
	 */
	public function assertUsable(): void;

	/**
	 * Administrative admission; the HTTP adapter supplies the required canonical revision.
	 * The local callback returns whether raw policy changed; it may throw SnapshotInvalidated when an epoch,
	 * cause set or single-use row differs from its external observation. A logical command id survives retries.
	 * @param string $expectedRevision Canonical revision supplied by the administrator.
	 * @param list<string> $uids every user whose official config cache may be touched
	 * @param Closure(array,string):mixed $prepare remote work, outside any transaction
	 * @param Closure(mixed,array,string,PolicyOutbox):bool $apply local work only, under the global mutex
	 * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool} cleanupPending: a cleanup or
	 *     audit event of the commit is not delivered yet (it is retried; the revocation itself is committed)
	 * @throws \InvalidArgumentException For an invalid revision or too many users.
	 * @throws PolicyConflict when the expected revision is stale, before or after the retries
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array;

	/**
	 * Internal commands (including processed directory events) still validate their observed revision and use CAS.
	 * Directory observations alone return false and never advance revision.
	 * @param list<string> $uids
	 * @param Closure(array,string):mixed $prepare
	 * @param Closure(mixed,array,string,PolicyOutbox):bool $apply
	 * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function runInternal(array $uids, Closure $prepare, Closure $apply): array;

	/**
	 * Runs local OAuth statements that are no policy change (revoking one session, or every session of one client)
	 * under the same mutex as issuance, so they serialize with it in the lock order of contracts §2.1.
	 *
	 * @template T
	 * @param Closure(): T $local local statements only
	 * @return T what $local returned
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function underMutex(Closure $local): mixed;

	/**
	 * The revision a command running at $revision commits; an exhausted counter is a storage failure (503).
	 *
	 * @param string $revision Canonical current revision.
	 * @return string The next canonical revision.
	 * @throws PolicyUnavailable when $revision is already the largest one a CAS may start from
	 */
	public static function next(string $revision): string;
}
