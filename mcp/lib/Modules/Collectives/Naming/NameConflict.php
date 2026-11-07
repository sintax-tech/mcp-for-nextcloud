<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Naming;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\FoundingReadback;
use OCA\Mcp\Modules\Collectives\Integration\TeamsDirectory;

/**
 * Whether a collective name is already taken by something the actor can see, and a snapshot of what they see now.
 *
 * When a team of that name exists and the actor is one of its admins, Collectives creates the collective for that
 * team instead of refusing (`CollectiveService.php` 4.0.0 `:190-204`, later `:183-198`). So a name is refused when it
 * matches, without case, any collective of the actor or any team listed for them; a team they cannot see is refused by
 * Collectives itself before it writes. The check runs again right before the creation, and the receipt compares with
 * the snapshot taken then: a race between the two can still make Collectives adopt a team, which the plan declares.
 */
class NameConflict {
	/**
	 * @param FoundingReadback $collectives the actor's collectives, read now
	 * @param TeamsDirectory $teams the teams listed for the actor, read now
	 */
	public function __construct(
		private FoundingReadback $collectives,
		private TeamsDirectory $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $name the name the plan shows
	 * @return array{taken:bool, collective_ids:list<int>, team_ids:list<string>} whether the name is taken, and the ids
	 *     of the actor's collectives and of the teams listed for them now
	 * @throws ToolFailure when Collectives or Teams cannot be read
	 */
	public function snapshot(string $uid, string $name): array {
		try {
			$collectives = $this->collectives->collectives($uid);
		} catch (\Throwable $e) {
			throw CollectivesGateway::failure($e);
		}
		$teams = $this->teams->visibleTeams($uid);
		$wanted = mb_strtolower($name);
		$names = array_merge(array_column($collectives, 'name'), ...array_column($teams, 'names'));
		return [
			'taken' => in_array($wanted, array_map(static fn (string $taken): string => mb_strtolower($taken), $names), true),
			'collective_ids' => array_column($collectives, 'id'),
			'team_ids' => array_column($teams, 'id'),
		];
	}
}
