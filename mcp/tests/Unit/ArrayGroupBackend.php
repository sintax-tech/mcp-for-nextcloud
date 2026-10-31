<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;

/**
 * A group backend over an array, read on every call: the public GroupInterface surface the app reads fresh
 * (MembershipReader, GroupSourceClassifier, LogsRoleReader). Named 'Database' by default, the one source whose groups
 * may grant; any other name stands for LDAP, SAML or a third-party backend.
 */
final class ArrayGroupBackend implements GroupInterface, INamedBackend {
	/**
	 * @param \Closure(): array<string, list<string>> $groups members by group id, read on every call
	 * @param (\Closure(string): void)|null $onExists runs before each groupExists(), the classification read that
	 *     follows the lifecycle read of a resolution: the moment another process may change a lifecycle
	 */
	public function __construct(private \Closure $groups, private string $name = 'Database', private ?\Closure $onExists = null) {}

	public function getBackendName(): string {
		return $this->name;
	}

	public function implementsActions($actions) {
		return false;
	}

	public function inGroup($uid, $gid) {
		return in_array($uid, ($this->groups)()[$gid] ?? [], true);
	}

	public function getUserGroups($uid) {
		$gids = [];
		foreach (($this->groups)() as $gid => $members) {
			if (in_array($uid, $members, true)) {
				$gids[] = (string)$gid;
			}
		}
		return $gids;
	}

	public function getGroups(string $search = '', int $limit = -1, int $offset = 0) {
		return array_map('strval', array_keys(($this->groups)()));
	}

	public function groupExists($gid) {
		if ($this->onExists !== null) {
			($this->onExists)((string)$gid);
		}
		return array_key_exists($gid, ($this->groups)());
	}

	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
		return array_values(($this->groups)()[$gid] ?? []);
	}
}
