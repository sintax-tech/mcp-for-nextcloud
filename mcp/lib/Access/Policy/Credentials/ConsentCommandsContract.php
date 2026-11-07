<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public ConsentCommands contract inherited by the historical policy facade. */
interface ConsentCommandsContract {
	/**
	 * Consent (contracts §3.1): connected=true and a new authorization code in one command, so a failure leaves
	 * neither. The code gets the next generation of the user (issue_seq + 1) and the current global epoch. Other
	 * sessions of the user are untouched.
	 *
	 * The eligibility is decided by $admit in the external phase (it may read group backends); under the mutex the
	 * service and the user's individual value are read again, and every group that made the user eligible must still
	 * be active, its gen renewed by this commit (holdLifecycles()): a pending_delete between the phases does not move
	 * the revision, so without that comparison a consent could issue on the policy of a group being deleted, and a
	 * deletion's Before that read the old gen must lose its transition and see the code this consent issues.
	 *
	 * @param string $uid the consenting user
	 * @param Closure(): (list<array{groupId:string, gen:string}>|null) $admit GrantPolicy::admission() of an enabled
	 *     account; null when the user may not consent
	 * @param Closure(string, string): void $insertCode stores the code with (generation, epoch); runs under the mutex
	 * @param (Closure(string, list<array>): array<string, bool>)|null $classify consumes the pending causes before the
	 *     code is issued (runCredential())
	 * @return bool false when the account is disabled, the service is off or the user is not eligible: nothing written
	 * @throws \InvalidArgumentException for a user the server does not know
	 * @throws PolicyUnavailable when the eligibility is unknown, the policy storage fails, the granting groups keep
	 *     changing, or the outcome is uncertain ($uncertain = true)
	 */
	public function consent(string $uid, Closure $admit, Closure $insertCode, ?Closure $classify = null): bool;
}
