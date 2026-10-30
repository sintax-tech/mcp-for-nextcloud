<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Columns;

use OCA\Mcp\Tools\Tables\TablesText;

/**
 * What a column looks like in an answer, from the fields the Tables app serializes (Column::jsonSerialize(), the same
 * keys in 1.0.x and 2.x, plus uuid and technicalName in 2.x, which are left out): its id, table, title, type, subtype,
 * description and whether a value is mandatory, the view's read-only and mandatory settings when it comes through a view,
 * and only the options of its own type ({@see ColumnOptions}). Who created or changed it is left out.
 */
final class ColumnShape {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * One column.
	 *
	 * @param object $column OCA\Tables\Db\Column the user may see
	 * @return array<string, mixed> the column
	 */
	public static function of(object $column): array {
		$data = (array)$column->jsonSerialize();
		$type = (string)($data['type'] ?? '');
		$shaped = [
			'id' => (int)($data['id'] ?? 0),
			'tableId' => (int)($data['tableId'] ?? 0),
			'title' => TablesText::clean($data['title'] ?? ''),
			'type' => TablesText::clean($type),
			'subtype' => TablesText::clean($data['subtype'] ?? ''),
			'description' => TablesText::clean($data['description'] ?? ''),
			'mandatory' => (bool)($data['mandatory'] ?? false),
		];
		$view = self::viewSettings($data['viewColumnInformation'] ?? null);
		if ($view !== null) {
			$shaped['view'] = $view;
		}
		$options = ColumnOptions::of($type, $data);
		return $shaped + ($options === [] ? [] : ['options' => $options]);
	}

	/**
	 * The read-only and mandatory settings a view gives the column.
	 *
	 * @param mixed $information the serialized ViewColumnInformation, or null outside a view
	 * @return array{readonly:bool, mandatory:bool}|null the settings, or null outside a view
	 */
	private static function viewSettings(mixed $information): ?array {
		if ($information instanceof \JsonSerializable) {
			$information = $information->jsonSerialize();
		}
		if (!is_array($information)) {
			return null;
		}
		return ['readonly' => (bool)($information['readonly'] ?? false), 'mandatory' => (bool)($information['mandatory'] ?? false)];
	}
}
