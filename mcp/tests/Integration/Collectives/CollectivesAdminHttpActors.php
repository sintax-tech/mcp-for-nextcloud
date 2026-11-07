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

/**
 * Owns what the C04 HTTP tests change in the cell and puts it back exactly, like TablesSchemaHttpActors: the owner's
 * and the outsider's MCP access and the owner's two C04 grants (resolved value through the official writer, then the
 * raw preference, an absent key included), the test group and the outsider's place in it, and the memberships of the
 * collective's team the tests add. Everything is captured before the first change; a membership that already existed
 * is never touched, so the tests refuse to run over it instead.
 */
final class CollectivesAdminHttpActors {
	/** Group the member tests add and remove. */
	public const GROUP = 'mcp-it-c04';
	/** C04 grants of the owner. */
	private const GRANTS = ['collective.create', 'members'];

	/** @var array<string, array<string, array{raw:?string, allowed:bool}>> captured cells by account, absent keys included */
	private array $before = [];
	private bool $groupCreated = false;
	private bool $outsiderAdded = false;
	/** @var list<string> memberships of the team (`type:userId`) absent before, which the tests may add */
	private array $absent = [];
	private ?string $team = null;

	/**
	 * @param GrantPolicy $policy official writer and resolver of MCP access
	 * @param IConfig $config raw preferences, for lossless restoration of absent keys and stored values
	 * @param IGroupManager $groups groups of the cell
	 * @param IUserManager $users accounts of the cell
	 * @param string $owner the collective's owner
	 * @param string $outsider the account outside the collective
	 */
	public function __construct(private GrantPolicy $policy, private IConfig $config, private IGroupManager $groups,
		private IUserManager $users, private string $owner, private string $outsider) {
	}

	/**
	 * Captures everything, then grants the owner the C04 operations and both accounts MCP access, and puts the
	 * outsider in the test group.
	 * @param int $cid the collective whose team the tests change
	 * @return list<string> memberships (`type:userId`) of the test accounts that existed before: the tests must not run
	 */
	public function enable(int $cid): array {
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
		$this->team = NativeTeamsProbe::team($cid, $this->owner);
		$members = NativeTeamsProbe::members($this->owner, $this->team);
		$watched = ['1:' . $this->outsider, '2:' . self::GROUP];
		$present = array_values(array_intersect($watched, array_keys($members)));
		$this->absent = array_values(array_diff($watched, $present));
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

	/** @return void every captured thing back as it was; each step runs even when an earlier one fails */
	public function restore(): void {
		try {
			foreach ($this->absent as $membership) {
				NativeTeamsProbe::removeIfPresent($this->owner, (string)$this->team, $membership);
			}
		} finally {
			try {
				$this->restoreAccess();
			} finally {
				$this->restoreGroup();
			}
		}
	}

	/** @return void resolved access through the writer, then exact raw preferences, absent keys included */
	private function restoreAccess(): void {
		foreach ($this->before as $uid => $cells) {
			foreach ($cells as $key => $before) {
				if ($key === GrantPolicy::ELIGIBLE_KEY) {
					$this->policy->setEligible($uid, $before['allowed']);
				} elseif ($key === GrantPolicy::CONNECTED_KEY) {
					$this->policy->setConnected($uid, $before['allowed']);
				} else {
					$this->policy->setGrant($uid, 'collectives', substr($key, strlen('grant_collectives_')), $before['allowed']);
				}
				if ($before['raw'] === null) {
					$this->config->deleteUserValue($uid, 'mcp', $key);
				} else {
					$this->config->setUserValue($uid, 'mcp', $key, $before['raw']);
				}
			}
		}
		$this->before = [];
	}

	/** @return void the outsider out of the group if this test put it there, and the group gone if this test created it */
	private function restoreGroup(): void {
		$group = $this->groups->get(self::GROUP);
		$outsider = $this->users->get($this->outsider);
		if ($group !== null && $outsider !== null && $this->outsiderAdded) {
			$group->removeUser($outsider);
		}
		if ($group !== null && $this->groupCreated) {
			$group->delete();
		}
		$this->outsiderAdded = false;
		$this->groupCreated = false;
	}
}
