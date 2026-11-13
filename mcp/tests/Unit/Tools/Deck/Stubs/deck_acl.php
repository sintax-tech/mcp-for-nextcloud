<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Deck ACL entry double, with the constants of Deck `lib/Db/Acl.php` and the getters a hydrated entry answers.
 */

namespace OCA\Deck\Db;

/** Permission levels used by the ACL tables; values taken from Deck `lib/Db/Acl.php:25-28`. */
class Acl {
	public const PERMISSION_READ = 0;
	public const PERMISSION_EDIT = 1;
	public const PERMISSION_SHARE = 2;
	public const PERMISSION_MANAGE = 3;

	public const PERMISSION_TYPE_USER = 0;
	public const PERMISSION_TYPE_GROUP = 1;
	public const PERMISSION_TYPE_CIRCLE = 7;

	/**
	 * @param array{type?: int, participant?: string, permissionEdit?: bool, permissionShare?: bool, permissionManage?: bool} $fields Values the getters report.
	 */
	public function __construct(private array $fields = []) {
	}

	/** @return int One of the `PERMISSION_TYPE_*` values. */
	public function getType(): int {
		return (int)($this->fields['type'] ?? self::PERMISSION_TYPE_USER);
	}

	/** @return string User, group or circle id the board is shared with. */
	public function getParticipant(): string {
		return (string)($this->fields['participant'] ?? '');
	}

	/** @return bool Whether the participant may edit. */
	public function getPermissionEdit(): bool {
		return (bool)($this->fields['permissionEdit'] ?? false);
	}

	/** @return bool Whether the participant may share. */
	public function getPermissionShare(): bool {
		return (bool)($this->fields['permissionShare'] ?? false);
	}

	/** @return bool Whether the participant may manage. */
	public function getPermissionManage(): bool {
		return (bool)($this->fields['permissionManage'] ?? false);
	}
}
