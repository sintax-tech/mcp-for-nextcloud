<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Presentation\RowWriteMessages;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;

/**
 * The arguments of tables_create_row and tables_update_row: exactly one of table_id and view_id, the row for an update,
 * and the cells as a list of 1 to 100 closed objects {column_id, value} with each column once. The registry's validator
 * takes no union of shapes (a value is a string, a number, a list or an object by column type), so `cells` is declared as
 * a list of 1 to 100 items and the shape of each item is checked here, with the same kind of error: nothing reaches the
 * Tables app before the whole list is valid, and a plan never shows two changes of one column (Tables would keep only one).
 */
final class RowWriteRequest {
	/** Most cells in one call. */
	public const MAX_CELLS = 100;

	/**
	 * Receives the checked arguments.
	 *
	 * @param string $targetType 'table' or 'view'
	 * @param int $targetId id of the table or view
	 * @param int|null $rowId the row of an update, null for a creation
	 * @param array<int, mixed> $cells value by column id, in the order given
	 * @param bool $confirmShared whether the user agreed to write in a table owned by somebody else
	 * @return void
	 */
	public function __construct(
		public readonly string $targetType,
		public readonly int $targetId,
		public readonly ?int $rowId,
		public readonly array $cells,
		public readonly bool $confirmShared,
	) {
	}

	/**
	 * Reads the arguments the registry validated against {@see self::schema()}.
	 *
	 * @param array<string, mixed> $arguments validated arguments
	 * @param bool $update whether the tool updates a row
	 * @return self the request
	 * @throws ArgumentValidationException for both or neither target, or a malformed cell list
	 */
	public static function of(array $arguments, bool $update): self {
		$tableId = isset($arguments['table_id']) ? (int)$arguments['table_id'] : null;
		$viewId = isset($arguments['view_id']) ? (int)$arguments['view_id'] : null;
		if (($tableId === null) === ($viewId === null)) {
			throw new ArgumentValidationException('Invalid arguments', '', TablesMessages::oneOfTableOrView());
		}
		$items = $arguments['cells'] ?? null;
		if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > self::MAX_CELLS) {
			throw self::invalid('cells');
		}
		$cells = [];
		foreach ($items as $index => $item) {
			$path = 'cells[' . $index . ']';
			if (!is_array($item) || ($item !== [] && array_is_list($item)) || array_diff(array_keys($item), ['column_id', 'value']) !== []
				|| !array_key_exists('column_id', $item) || !array_key_exists('value', $item)) {
				throw self::invalid($path);
			}
			$columnId = $item['column_id'];
			if (!is_int($columnId) || $columnId < 1 || array_key_exists($columnId, $cells)) {
				throw self::invalid($path . '.column_id');
			}
			$cells[$columnId] = $item['value'];
		}
		return new self($viewId !== null ? 'view' : 'table', (int)($viewId ?? $tableId), $update ? (int)$arguments['row_id'] : null, $cells,
			($arguments['confirm_shared'] ?? false) === true);
	}

	/**
	 * The arguments of the tool, as the registry validates them; `confirm` is added by the registry.
	 *
	 * @param bool $update whether the tool updates a row
	 * @return array{properties: array<string, mixed>, required: list<string>} the properties and the required ones
	 */
	public static function schema(bool $update): array {
		$properties = [
			'table_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the table, as tables_list_tables returns it. Give this or view_id, not both.'],
			'view_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the view, as tables_list_views returns it. Give this or table_id, not both.'],
		];
		if ($update) {
			$properties['row_id'] = ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the row, as tables_list_rows returns it.'];
		}
		// The shape of each item is checked by of(): a value is a string, a number, a list or an object by column type, and
		// the validator takes no union of shapes. `examples` shows one valid list.
		$properties['cells'] = ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CELLS, 'description' => RowWriteMessages::CELLS,
			'examples' => [[['column_id' => 1, 'value' => 'text']]]];
		$properties['confirm_shared'] = ['type' => 'boolean', 'description' => RowWriteMessages::CONFIRM_SHARED];
		$properties[PlanState::ARGUMENT] = PlanState::property();
		return ['properties' => $properties, 'required' => $update ? ['row_id', 'cells'] : ['cells']];
	}

	/**
	 * The error of a malformed part of the cell list.
	 *
	 * @param string $path the part, a fixed path of ours
	 * @return ArgumentValidationException the error
	 */
	private static function invalid(string $path): ArgumentValidationException {
		return new ArgumentValidationException('Invalid argument: ' . $path, $path, RowWriteMessages::cellsRule());
	}
}
