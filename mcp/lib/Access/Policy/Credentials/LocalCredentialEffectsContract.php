<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use OCA\Mcp\Access\Policy\SnapshotInvalidated;

/** Public LocalCredentialEffects contract inherited by the historical policy facade. */
interface LocalCredentialEffectsContract {
	/**
	 * Under the mutex of a command whose decision relied on group lifecycles read in its external phase: an issuance
	 * a group admitted (consent, exchange, refresh), or a reset that leaves a user eligible through a group. Each of
	 * them must still be active, and this commit renews its gen by a compare-and-set, so the command and a deletion
	 * of the group are ordered (F1 of the second reading of 1.1.0), without the pre-event ever taking the mutex:
	 * - a deletion's Before that read the gen before this commit loses its pending_delete transition and judges the
	 *   holders again, now seeing what this command issued or left (GroupLifecycleListener::markPending());
	 * - a pending_delete committed after the read below makes the renewal match nothing, and the attempt is replayed,
	 *   so its external phase reads pending_delete and refuses; one waiting on this row's lock matches nothing once
	 *   this transaction commits.
	 *
	 * A gen other than the one the external phase read is accepted while the row is still active: the revision did
	 * not move between the phases (run() checks it), and the only lifecycle change that keeps a group active without
	 * moving the revision is this renewal by another command, so the policy the decision relied on still holds and
	 * concurrent issuances admitted by one group do not replay each other.
	 *
	 * @param list<array{groupId:string, gen:string}> $lifecycles what the external phase read
	 * @throws SnapshotInvalidated when one of them is no longer active, or moved before its renewal
	 * @throws \LogicException outside the local phase of an attempt
	 * @throws \OCP\DB\Exception when a statement fails
	 */
	public function holdLifecycles(array $lifecycles): void;
}
