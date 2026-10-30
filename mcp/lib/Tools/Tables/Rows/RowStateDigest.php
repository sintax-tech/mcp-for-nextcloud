<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * A canonical digest of the state a page was read under: the columns the user sees (in their order, with type, subtype,
 * options and defaults), the columns the saved filter and sort name (hidden ones included, with the same metadata), the
 * saved filter and sort as stored, and the table. A change to any of them between two pages changes the digest, so a
 * cursor of the old state is refused instead of paging a different query. It is never shown: only the cursor's MAC
 * carries it.
 */
final class RowStateDigest {
	/** Fields of a column that change what a query means. */
	private const COLUMN_FIELDS = ['id', 'tableId', 'type', 'subtype', 'selectionOptions', 'selectionDefault', 'numberDefault',
		'textDefault', 'datetimeDefault', 'usergroupDefault', 'customSettings'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The digest.
	 *
	 * @param int $tableId the table of the target
	 * @param list<array<string, mixed>> $projection the serialized columns the user sees
	 * @param list<array<string, mixed>> $referenced the serialized columns the saved rules name
	 * @param string|null $savedFilter the stored filter of the view
	 * @param string|null $savedSort the stored sort of the view or the table
	 * @return string SHA-256 hex
	 */
	public static function of(int $tableId, array $projection, array $referenced, ?string $savedFilter, ?string $savedSort): string {
		return hash('sha256', json_encode([$tableId, self::kept($projection), self::kept($referenced), $savedFilter, $savedSort],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * The fields of columns that change what a query means.
	 *
	 * @param list<array<string, mixed>> $columns serialized columns
	 * @return list<array<string, mixed>> the kept fields
	 */
	private static function kept(array $columns): array {
		return array_map(static function (array $column): array {
			$kept = [];
			foreach (self::COLUMN_FIELDS as $field) {
				$kept[$field] = $column[$field] ?? null;
			}
			return $kept;
		}, $columns);
	}
}
