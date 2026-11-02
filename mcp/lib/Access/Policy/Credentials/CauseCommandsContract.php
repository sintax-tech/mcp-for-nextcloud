<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public CauseCommands contract inherited by the historical policy facade. */
interface CauseCommandsContract {
	/**
	 * The cause protocol of contracts §3.3.1 for one user, outside issuance: an After event, a deferred After, the
	 * preflight of a request and the reconciler. The whole cause set R0 is read before the external phase; the
	 * selected causes are classified there; under the mutex the set must still be R0 (else the attempt is replayed,
	 * at most twice), and each selected cause is consumed with its exact gen, after the cut its classification
	 * demands, in one transaction that advances the revision. Causes not selected are compared but never touched.
	 *
	 * @param string $uid owner of the causes
	 * @param Closure(string, list<array>): array<string, bool> $classify CauseClassifier::classify()
	 * @param (Closure(array): bool)|null $select keeps a cause row of R0; null processes all of them
	 * @return int causes consumed by this call; 0 also when nothing was selected (no transaction is opened then)
	 * @throws \InvalidArgumentException for an invalid user id
	 * @throws PolicyUnavailable when the storage fails, the set keeps changing, a cause cannot be classified, or the
	 *     outcome is uncertain
	 */
	public function processCauses(string $uid, Closure $classify, ?Closure $select = null): int;
}
