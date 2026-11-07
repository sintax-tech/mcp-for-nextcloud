<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCA\Mcp\Access\Grants\GrantPolicy;

/** Prepares only scene actors; native Forms/Polls seeding must not materialize or toggle the global service switch. */
final class ScenePolicySetup {
	/**
	 * @param GrantPolicy $policy Official policy writer for the disposable fixture actors.
	 * @param list<string> $uids Synthetic scene users, separate from the upgrade snapshot users.
	 * @param string|null $readModule Optional scene-specific read grant.
	 * @return void Per-user eligibility, connection and optional read permission are prepared without global changes.
	 */
	public static function actors(GrantPolicy $policy, array $uids, ?string $readModule = null): void {
		foreach ($uids as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			if ($readModule !== null) {
				$policy->setGrant($uid, $readModule, 'read', true);
			}
		}
	}
}
