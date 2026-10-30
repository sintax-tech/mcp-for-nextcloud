<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The users, groups and teams a user/group cell may name, one rule for every pinned release and column:
 * - type 0 (user) and 1 (group) when the column allows them (usergroupSelectUsers/usergroupSelectGroups), each id checked
 *   to exist (IUserManager::userExists()/IGroupManager::groupExists()), exactly as given, without searching anything;
 * - type 2 (team) only in the releases whose own validation checks a team against the caller (1.0.10, 2.2.2 and later:
 *   UsergroupBusiness::validateValue() → CircleHelper::circleExists($id, $userId)), when the column allows teams and the
 *   Circles app is enabled for the caller; the membership itself stays the Tables app's to check;
 * - more than one entry only when the column allows several (usergroupMultipleItems), never the same entry twice;
 * - every other type is refused, federated ones included.
 */
class UsergroupWhitelist {
	/** User entry. */
	public const USER = 0;

	/** Group entry. */
	public const GROUP = 1;

	/** Team (Circles) entry. */
	public const TEAM = 2;

	/** Longest id accepted. */
	private const MAX_ID = 255;

	/**
	 * Receives the user, group and app managers.
	 *
	 * @param IUserManager $users whether a user exists
	 * @param IGroupManager $groups whether a group exists
	 * @param IAppManager $apps whether Circles is enabled for the caller
	 * @return void
	 */
	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
		private IAppManager $apps,
	) {
	}

	/**
	 * The checked entries of a user/group cell.
	 *
	 * @param array<string, mixed> $column the column as Column::jsonSerialize() gives it
	 * @param mixed $value the value the tool was given
	 * @param string $version installed Tables version
	 * @param string $userId authenticated user UID
	 * @return list<array{id:string, type:int}> the entries
	 * @throws RowWriteRefusal when an entry is malformed, of a type the column or release does not take, or unknown
	 */
	public function entries(array $column, mixed $value, string $version, string $userId): array {
		if (!is_array($value) || !array_is_list($value) || (count($value) > 1 && !($column['usergroupMultipleItems'] ?? false))) {
			throw RowWriteRefusal::value();
		}
		$entries = [];
		foreach ($value as $entry) {
			if (!is_array($entry) || array_is_list($entry) || array_diff(array_keys($entry), ['id', 'type']) !== []
				|| !is_string($entry['id'] ?? null) || $entry['id'] === '' || mb_strlen($entry['id']) > self::MAX_ID || !is_int($entry['type'] ?? null)) {
				throw RowWriteRefusal::value();
			}
			$checked = ['id' => $entry['id'], 'type' => $entry['type']];
			if (in_array($checked, $entries, true) || !$this->allowed($column, $checked, $version, $userId)) {
				throw RowWriteRefusal::value();
			}
			$entries[] = $checked;
		}
		return $entries;
	}

	/**
	 * Whether the release keeps team entries under its own check against the caller.
	 *
	 * @param string $version installed Tables version
	 * @return bool true for 1.0.10 up to 2.0, and 2.2.2 on
	 */
	public static function checksTeams(string $version): bool {
		return version_compare($version, '1.0.10', '>=')
			&& !(version_compare($version, '2.0.0', '>=') && version_compare($version, '2.2.2', '<'));
	}

	/**
	 * Whether one entry may be written in the column.
	 *
	 * @param array<string, mixed> $column the column
	 * @param array{id:string, type:int} $entry the entry
	 * @param string $version installed Tables version
	 * @param string $userId authenticated user UID
	 * @return bool true when it may
	 */
	private function allowed(array $column, array $entry, string $version, string $userId): bool {
		return match ($entry['type']) {
			self::USER => (bool)($column['usergroupSelectUsers'] ?? false) && $this->users->userExists($entry['id']),
			self::GROUP => (bool)($column['usergroupSelectGroups'] ?? false) && $this->groups->groupExists($entry['id']),
			self::TEAM => (bool)($column['usergroupSelectTeams'] ?? false) && self::checksTeams($version) && $this->circlesEnabled($userId),
			default => false,
		};
	}

	/**
	 * Whether the Circles app is enabled for the caller.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool true when it is
	 */
	private function circlesEnabled(string $userId): bool {
		$user = $this->users->get($userId);
		return $user !== null && $this->apps->isEnabledForUser('circles', $user);
	}
}
