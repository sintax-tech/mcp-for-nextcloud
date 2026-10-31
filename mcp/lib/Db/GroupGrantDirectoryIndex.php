<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCP\DB\IResult;
use OCP\IDBConnection;

/** Preserves policy-only directory identities while counting only recognized stored grants. */
final class GroupGrantDirectoryIndex {
	/**
	 * Keeps the existing two directory scans; grant classification needs no extra per-group query.
	 * @param IDBConnection $db App-owned storage connection.
	 * @param string $table One of the directory's grant/lifecycle tables.
	 * @return IResult Identity rows, including grant names for the grant-table scan.
	 */
	public static function rows(IDBConnection $db, string $table): IResult {
		$columns = $table === 'mcp_group_grants' ? ['group_key', 'group_id', 'module', 'operation'] : ['group_key', 'group_id'];
		return $db->getQueryBuilder()->select(...$columns)->from($table)->executeQuery();
	}

	/**
	 * @param string $table Scanned table.
	 * @param array $row Stored directory-index row; identity is validated by the caller.
	 * @return bool Whether the row counts towards the known-grant filter, regardless of group source/lifecycle.
	 */
	public static function hasKnownGrant(string $table, array $row): bool {
		return $table === 'mcp_group_grants' && is_string($row['module'] ?? null) && is_string($row['operation'] ?? null)
			&& StoredGroupGrant::known($row['module'], $row['operation']);
	}
}
