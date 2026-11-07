<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Throwable;

/**
 * Owns what the C04 HTTP tests change in the cell and puts it back exactly, like TablesSchemaHttpActors: the owner's
 * and the outsider's MCP access and the owner's Collectives write grants, those of the page tools (C01–C03) and the
 * two of C04, so the whole catalog is published whatever ran before (resolved value through the official writer, then the
 * raw preference, an absent key included), the test group and the outsider's place in it, and the memberships of the
 * collective's team the tests add. Everything is captured before the first change. The member tests work on a
 * collective they create themselves, so its team has no membership of the test accounts before; {@see self::watch()}
 * records that team, and a membership that existed there would never be touched. Restoring tries every item on its own, each membership,
 * each access cell (writer and raw preference) and each group step, and raises one {@see RestoreFailures} at the end
 * with all that failed: no restoration is skipped and no failure is hidden.
 */
final class CollectivesAdminHttpActors {
	/** Group the member tests add and remove. */
	public const GROUP = 'mcp-it-c04';
	/** Collectives write grants of the owner: the page tools (C01–C03), then C04. */
	private const GRANTS = ['create', 'edit', 'move', 'collective.create', 'members'];

	/** @var array<string, array<string, array{raw:?string, allowed:bool}>> captured cells by account, absent keys included */
	private array $before = [];
	private bool $groupCreated = false;
	private bool $outsiderAdded = false;
	/** @var list<string> memberships of the team (`type:userId`) absent before, which the tests may add */
	private array $absent = [];
	private ?string $team = null;
	/** @var \Closure(int, string): string the team of a collective, read as its owner */
	private \Closure $teamOf;
	/** @var \Closure(string, string): array<string, mixed> the memberships of a team, by `type:userId` */
	private \Closure $membersOf;
	/** @var \Closure(string, string, string): void removes one membership, as the team's owner */
	private \Closure $removeMembership;

	/**
	 * @param GrantPolicy $policy official writer and resolver of MCP access
	 * @param IConfig $config raw preferences, for lossless restoration of absent keys and stored values
	 * @param IGroupManager $groups groups of the cell
	 * @param IUserManager $users accounts of the cell
	 * @param string $owner the collective's owner
	 * @param string $outsider the account outside the collective
	 * @param (\Closure(int, string): string)|null $teamOf team of a collective; default {@see NativeTeamsProbe::team()}
	 * @param (\Closure(string, string): array<string, mixed>)|null $membersOf memberships; default {@see NativeTeamsProbe::members()}
	 * @param (\Closure(string, string, string): void)|null $removeMembership removal; default {@see NativeTeamsProbe::removeIfPresent()}
	 */
	public function __construct(private GrantPolicy $policy, private IConfig $config, private IGroupManager $groups,
		private IUserManager $users, private string $owner, private string $outsider, ?\Closure $teamOf = null, ?\Closure $membersOf = null,
		?\Closure $removeMembership = null) {
		$this->teamOf = $teamOf ?? static fn (int $cid, string $uid): string => NativeTeamsProbe::team($cid, $uid);
		$this->membersOf = $membersOf ?? static fn (string $uid, string $team): array => NativeTeamsProbe::members($uid, $team);
		$this->removeMembership = $removeMembership ?? static function (string $uid, string $team, string $key): void {
			NativeTeamsProbe::removeIfPresent($uid, $team, $key);
		};
	}

	/**
	 * Captures the access and the group, then grants the owner every Collectives write and both accounts MCP access, and puts
	 * the outsider in the test group.
	 * @return void
	 */
	public function enable(): void {
		foreach ([$this->owner => self::GRANTS, $this->outsider => []] as $uid => $grants) {
			$cells = [GrantPolicy::ELIGIBLE_KEY => $this->policy->eligible($uid), GrantPolicy::CONNECTED_KEY => $this->policy->connected($uid)];
			foreach ($grants as $operation) {
				$cells['grant_collectives_' . $operation] = $this->policy->granted($uid, 'collectives', $operation);
			}
			$keys = $this->config->getUserKeys($uid, 'mcp');
			foreach ($cells as $key => $allowed) {
				$this->before[$uid][$key] = ['raw' => in_array($key, $keys, true) ? $this->config->getUserValue($uid, 'mcp', $key) : null, 'allowed' => $allowed];
			}
		}
		foreach ([$this->owner, $this->outsider] as $uid) {
			$this->policy->setEligible($uid, true);
			$this->policy->setConnected($uid, true);
		}
		foreach (self::GRANTS as $operation) {
			$this->policy->setGrant($this->owner, 'collectives', $operation, true);
		}
		$group = $this->groups->get(self::GROUP);
		if ($group === null) {
			$group = $this->groups->createGroup(self::GROUP);
			$this->groupCreated = true;
		}
		$outsider = $this->users->get($this->outsider);
		if ($outsider !== null && !$group->inGroup($outsider)) {
			$group->addUser($outsider);
			$this->outsiderAdded = true;
		}
	}

	/**
	 * Records the team of a collective the test changes, and which memberships of the test accounts it lacks now: only
	 * those are removed again on restore.
	 * @param int $cid the collective, created by the test
	 * @return list<string> memberships (`type:userId`) of the test accounts it already has; a new collective has none
	 */
	public function watch(int $cid): array {
		$this->team = ($this->teamOf)($cid, $this->owner);
		$members = ($this->membersOf)($this->owner, $this->team);
		$watched = ['1:' . $this->outsider, '2:' . self::GROUP];
		$present = array_values(array_intersect($watched, array_keys($members)));
		$this->absent = array_values(array_diff($watched, $present));
		return $present;
	}

	/**
	 * @param string $operation a C04 operation
	 * @param bool $allowed whether the owner has it for the next step of the test
	 * @return void
	 */
	public function grant(string $operation, bool $allowed): void {
		$this->policy->setGrant($this->owner, 'collectives', $operation, $allowed);
	}

	/**
	 * Every captured thing back as it was. Each item is tried on its own, whatever the others did.
	 * @return void
	 * @throws RestoreFailures after every item was tried, when any of them failed
	 */
	public function restore(): void {
		$failures = [];
		$attempt = static function (string $step, \Closure $work) use (&$failures): void {
			try {
				$work();
			} catch (Throwable $error) {
				$failures[] = ['step' => $step, 'error' => $error];
			}
		};
		foreach ($this->absent as $membership) {
			$attempt('membership ' . $membership, fn () => ($this->removeMembership)($this->owner, (string)$this->team, $membership));
		}
		$this->absent = [];
		foreach ($this->before as $uid => $cells) {
			foreach ($cells as $key => $before) {
				$attempt("$uid $key", fn () => $this->restoreCell($uid, $key, $before['allowed']));
				$attempt("$uid $key raw", fn () => $before['raw'] === null
					? $this->config->deleteUserValue($uid, 'mcp', $key) : $this->config->setUserValue($uid, 'mcp', $key, $before['raw']));
			}
		}
		$this->before = [];
		$attempt('group membership of the outsider', fn () => $this->restoreGroupMember());
		$attempt('group', fn () => $this->restoreGroup());
		if ($failures !== []) {
			throw new RestoreFailures($failures);
		}
	}

	/**
	 * @param string $uid an account
	 * @param string $key a captured cell
	 * @param bool $allowed its resolved value before the test
	 * @return void the value back through the official writer
	 */
	private function restoreCell(string $uid, string $key, bool $allowed): void {
		if ($key === GrantPolicy::ELIGIBLE_KEY) {
			$this->policy->setEligible($uid, $allowed);
		} elseif ($key === GrantPolicy::CONNECTED_KEY) {
			$this->policy->setConnected($uid, $allowed);
		} else {
			$this->policy->setGrant($uid, 'collectives', substr($key, strlen('grant_collectives_')), $allowed);
		}
	}

	/** @return void the outsider out of the group, if this test put it there */
	private function restoreGroupMember(): void {
		$group = $this->groups->get(self::GROUP);
		$outsider = $this->users->get($this->outsider);
		if ($group !== null && $outsider !== null && $this->outsiderAdded) {
			$group->removeUser($outsider);
		}
		$this->outsiderAdded = false;
	}

	/** @return void the group gone, if this test created it */
	private function restoreGroup(): void {
		$group = $this->groups->get(self::GROUP);
		if ($group !== null && $this->groupCreated) {
			$group->delete();
		}
		$this->groupCreated = false;
	}
}
