<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/**
 * The table or view a write goes through, as {@see RowWriteColumns} found it once the native write permission allowed it:
 * the columns the Tables app accepts there, their view settings and whether the table is the caller's own.
 */
final class RowWriteTarget {
	/**
	 * Receives what was found.
	 *
	 * @param string $type 'table' or 'view'
	 * @param int $id id of the table or view
	 * @param int $tableId the table the row belongs to
	 * @param object|null $view OCA\Tables\Db\View for a view, null for a table
	 * @param array<int, array<string, mixed>> $columns the accepted columns as Column::jsonSerialize() gives them, by id, in order
	 * @param array<int, array{readonly:bool, mandatory:bool}> $settings the view settings of each column, empty for a table
	 * @param bool $ownedByCaller whether the table belongs to the caller
	 * @param string $version installed Tables version
	 * @return void
	 */
	public function __construct(
		public readonly string $type,
		public readonly int $id,
		public readonly int $tableId,
		public readonly ?object $view,
		public readonly array $columns,
		public readonly array $settings,
		public readonly bool $ownedByCaller,
		public readonly string $version,
	) {
	}

	/**
	 * Whether a column may not be written here: the view marks it read-only.
	 *
	 * @param int $columnId the column
	 * @return bool true for a read-only column
	 */
	public function isReadonly(int $columnId): bool {
		return $this->settings[$columnId]['readonly'] ?? false;
	}

	/**
	 * Whether a column needs a value here: the column itself or the view marks it mandatory.
	 *
	 * @param int $columnId the column
	 * @return bool true for a mandatory column
	 */
	public function isMandatory(int $columnId): bool {
		return (bool)($this->columns[$columnId]['mandatory'] ?? false) || ($this->settings[$columnId]['mandatory'] ?? false);
	}
}
