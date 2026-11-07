<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use Closure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Throwable;

/**
 * What the actor sees of Teams, read in the actor's own session ({@see TeamsSession}): the teams listed for them, and
 * the owner and members of one team. Only reads; a team the actor cannot see stays unknown here, and Collectives
 * refuses its name itself, in a privileged read made before any write (`CircleHelper::existsCircle`, `:170-180`).
 */
class TeamsDirectory {
	/** User type of a local account in Teams (`Member::TYPE_USER`). */
	private const TYPE_USER = 1;

	/** @param TeamsSession $session the actor's Teams session */
	public function __construct(private TeamsSession $session) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @return list<array{id:string, names:list<string>}> the teams listed for the actor, with every name Teams matches on
	 * @throws ToolFailure when Teams cannot be read
	 */
	public function visibleTeams(string $uid): array {
		return $this->read(fn (): array => $this->session->asActor($uid, static fn (object $teams): array => array_values(array_map(
			static fn (object $team): array => ['id' => $team->getSingleId(),
				'names' => array_values(array_unique([$team->getName(), $team->getSanitizedName(), $team->getDisplayName()]))],
			array_filter($teams->getCircles(null, true), static fn (mixed $team): bool => is_object($team)),
		))));
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $teamId id of the team
	 * @return array{owner_is_actor:bool, only_member_is_actor:bool, display_name:string} who owns the team and who is in it
	 * @throws ToolFailure when the team cannot be read for the actor
	 */
	public function team(string $uid, string $teamId): array {
		return $this->read(fn (): array => $this->session->asActor($uid, static function (object $teams) use ($uid, $teamId): array {
			$team = $teams->getCircle($teamId, null);
			$owner = $team->getOwner();
			$members = array_values(array_filter($team->getMembers(0), static fn (mixed $member): bool => is_object($member)));
			$isActor = static fn (object $member): bool => $member->getUserType() === self::TYPE_USER && $member->getUserId() === $uid;
			return ['owner_is_actor' => $isActor($owner), 'only_member_is_actor' => count($members) === 1 && $isActor($members[0]),
				'display_name' => $team->getDisplayName()];
		}));
	}

	/**
	 * @template T
	 * @param Closure(): T $read a Teams read
	 * @return T what it returned
	 * @throws ToolFailure the module's answer for whatever the read threw
	 */
	private function read(Closure $read): mixed {
		try {
			return $read();
		} catch (Throwable $e) {
			throw CollectivesGateway::failure($e);
		}
	}
}
