<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use OCP\Server;

/**
 * What Collectives and Teams themselves hold, read in the test process through their own services, independently of
 * the MCP tools under test: the collectives of an account, the team of a collective and its memberships. Every Teams
 * read runs in the account's own session, closed in a `finally`. Only reads, except {@see self::removeIfPresent()},
 * which the tests use to put the cell back as they found it.
 */
final class NativeTeamsProbe {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $uid an account
	 * @return array<string, int> the collectives it is a member of, id by name
	 */
	public static function collectives(string $uid): array {
		$found = [];
		foreach (self::closing(static fn (): array => Server::get('OCA\\Collectives\\Service\\CollectiveService')->getCollectives($uid)) as $collective) {
			$found[(string)$collective->getName()] = (int)$collective->getId();
		}
		return $found;
	}

	/**
	 * @param int $cid a collective
	 * @param string $uid a member of it
	 * @return string the single id of its team
	 */
	public static function team(int $cid, string $uid): string {
		return (string)self::closing(static fn (): object => Server::get('OCA\\Collectives\\Service\\CollectiveService')->getCollective($cid, $uid))->getCircleId();
	}

	/**
	 * @param string $uid the account whose session reads the team
	 * @param string $teamId a team
	 * @return array<string, array{id:string, level:int, status:string}> its memberships, by `type:userId`; a group, which
	 *     Teams stores as a membership of the group's system team `group:<id>` of source group, is keyed `2:<id>`
	 */
	public static function members(string $uid, string $teamId): array {
		return self::asActor($uid, static function (object $teams) use ($teamId): array {
			$found = [];
			foreach ($teams->getCircle($teamId)->getMembers() as $member) {
				$found[self::key($member)] = ['id' => (string)$member->getId(), 'level' => (int)$member->getLevel(),
					'status' => (string)$member->getStatus()];
			}
			ksort($found);
			return $found;
		});
	}

	/**
	 * @param object $member a native Teams membership
	 * @return string `type:userId`, or `2:<group id>` for a group's system team
	 */
	private static function key(object $member): string {
		$team = (int)$member->getUserType() === 16 && $member->hasBasedOn() ? $member->getBasedOn() : null;
		if ($team !== null && (int)$team->getSource() === 2 && str_starts_with((string)$team->getName(), 'group:')) {
			return '2:' . substr((string)$team->getName(), strlen('group:'));
		}
		return $member->getUserType() . ':' . $member->getUserId();
	}

	/**
	 * @param string $uid an account
	 * @return list<string> single ids of the teams listed for it, sorted
	 */
	public static function teams(string $uid): array {
		$ids = self::asActor($uid, static fn (object $teams): array => array_map(static fn (object $team): string => (string)$team->getSingleId(),
			$teams->getCircles(null, true)));
		sort($ids);
		return $ids;
	}

	/**
	 * Puts the cell back: removes a membership a failed test may have left, through Teams, as the team's owner.
	 * @param string $owner the team's owner
	 * @param string $teamId the team
	 * @param string $key `type:userId` of the membership
	 * @return void
	 */
	public static function removeIfPresent(string $owner, string $teamId, string $key): void {
		$member = self::members($owner, $teamId)[$key] ?? null;
		if ($member !== null) {
			self::asActor($owner, static fn (object $teams) => $teams->removeMember($member['id']));
		}
	}

	/**
	 * @template T
	 * @param string $uid the account the session acts as
	 * @param \Closure(object): T $read what to do with the Teams manager
	 * @return T what it returned
	 */
	private static function asActor(string $uid, \Closure $read): mixed {
		$teams = Server::get('OCA\\Circles\\CirclesManager');
		try {
			$teams->startSession($teams->getLocalFederatedUser($uid));
			return $read($teams);
		} finally {
			$teams->stopSession();
		}
	}

	/**
	 * @template T
	 * @param \Closure(): T $read a Collectives read that may open a Teams session of its own
	 * @return T what it returned
	 */
	private static function closing(\Closure $read): mixed {
		try {
			return $read();
		} finally {
			Server::get('OCA\\Circles\\CirclesManager')->stopSession();
		}
	}
}
