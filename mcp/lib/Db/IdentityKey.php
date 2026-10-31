<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;

/**
 * Fixed-size keys for opaque Nextcloud ids: lowercase hex SHA-256 of a type tag, NUL and the exact id bytes. Only the
 * key is indexed; every read also compares the stored original id byte for byte, so a collision fails closed and
 * a case-insensitive collation can never merge two ids.
 */
final class IdentityKey {
	/** cause_key of an account deletion in mcp_auth_recheck */
	public const ACCOUNT_CAUSE = 'account';

	/**
	 * @param string $uid Nextcloud user id, exactly as the server returns it
	 * @return string the user_key
	 * @throws InvalidArgumentException for an empty id, a NUL byte or invalid UTF-8
	 */
	public static function user(string $uid): string {
		return hash('sha256', "user\0" . self::checked($uid));
	}

	/**
	 * @param string $gid Nextcloud group id, exactly as the server returns it
	 * @return string the group_key, also the cause_key of a group removal
	 * @throws InvalidArgumentException for an empty id, a NUL byte or invalid UTF-8
	 */
	public static function group(string $gid): string {
		return hash('sha256', "group\0" . self::checked($gid));
	}

	/**
	 * @param string $module scope module the resource belongs to
	 * @param string $id resource id, exactly as stored
	 * @return string the resource_key: the same id in two modules gives two keys
	 * @throws InvalidArgumentException for an empty id or module, a NUL byte or invalid UTF-8
	 */
	public static function resource(string $module, string $id): string {
		return hash('sha256', self::checked($module) . "\0" . self::checked($id));
	}

	/** @return string the cause_key of an account deletion */
	public static function accountCause(): string {
		return hash('sha256', self::ACCOUNT_CAUSE);
	}

	/**
	 * @return string $id unchanged
	 * @throws InvalidArgumentException for an empty id, a NUL byte or invalid UTF-8
	 */
	public static function checked(string $id): string {
		if ($id === '' || str_contains($id, "\0") || !mb_check_encoding($id, 'UTF-8')) {
			throw new InvalidArgumentException('Invalid identifier');
		}
		return $id;
	}
}
