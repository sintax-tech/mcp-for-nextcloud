<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Columns;

use OCA\Mcp\Tools\Tables\TablesText;

/**
 * The options of a column, only the ones the Tables app defines for its type, from what Column::jsonSerialize() returns:
 * - the fields of the type (numberMin, textMaxLength, selectionOptions, usergroupSelectUsers, showUserStatus and so on);
 * - for a relation column (Tables 2.2 on), the relation keys of `customSettings` (relationType, targetId,
 *   labelColumn), the constants Column::RELATION_* name. Nothing else of `customSettings` is returned: its display
 *   width is layout, and any other key is unknown to MCP. The related table, view or rows are never resolved.
 * tests/Unit/Contract/NextcloudApi/Tables/TablesBehaviorContractTest requires these lists to be exactly the options
 * each supported release defines, so a new native option cannot be left out or added without review.
 */
final class ColumnOptions {
	/** The serialized fields kept per column type. */
	public const TYPE_FIELDS = [
		'number' => ['numberDefault', 'numberMin', 'numberMax', 'numberDecimals', 'numberPrefix', 'numberSuffix'],
		'text' => ['textDefault', 'textAllowedPattern', 'textMaxLength', 'textUnique'],
		'selection' => ['selectionOptions', 'selectionDefault'],
		'datetime' => ['datetimeDefault'],
		'usergroup' => ['usergroupDefault', 'usergroupMultipleItems', 'usergroupSelectUsers', 'usergroupSelectGroups', 'usergroupSelectTeams', 'showUserStatus'],
		'relation' => [],
	];

	/** The keys of `customSettings` kept per column type. */
	public const CUSTOM_SETTINGS = [
		'relation' => ['relationType', 'targetId', 'labelColumn'],
	];

	/** Options that are text a user wrote. */
	private const TEXT_FIELDS = ['numberPrefix', 'numberSuffix', 'textDefault', 'textAllowedPattern'];

	/** Options that are ids. */
	private const ID_FIELDS = ['targetId', 'labelColumn'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The options of a column.
	 *
	 * @param string $type the column type
	 * @param array<string, mixed> $data what Column::jsonSerialize() returned
	 * @return array<string, mixed> the options, empty for a type without any or unknown to MCP
	 */
	public static function of(string $type, array $data): array {
		$options = [];
		foreach (self::TYPE_FIELDS[$type] ?? [] as $field) {
			if (array_key_exists($field, $data)) {
				$options[$field] = self::option($field, $data[$field]);
			}
		}
		$custom = $data['customSettings'] ?? [];
		$custom = $custom instanceof \stdClass ? (array)$custom : $custom;
		foreach (self::CUSTOM_SETTINGS[$type] ?? [] as $key) {
			if (is_array($custom) && array_key_exists($key, $custom)) {
				$options[$key] = self::option($key, $custom[$key]);
			}
		}
		return $options;
	}

	/**
	 * One option, with the text a user wrote cleaned.
	 *
	 * @param string $field option name
	 * @param mixed $value its value
	 * @return mixed the value to return
	 */
	private static function option(string $field, mixed $value): mixed {
		if (in_array($field, self::TEXT_FIELDS, true)) {
			return $value === null ? null : TablesText::clean($value);
		}
		if (in_array($field, self::ID_FIELDS, true)) {
			return is_numeric($value) ? (int)$value : null;
		}
		if ($field === 'selectionOptions' && is_array($value)) {
			return array_values(array_map(static fn (mixed $option): array => [
				'id' => is_array($option) ? (int)($option['id'] ?? 0) : 0,
				'label' => TablesText::clean(is_array($option) ? ($option['label'] ?? '') : $option),
			], $value));
		}
		return self::plain($value, 2);
	}

	/**
	 * A scalar as it is, a list or map of scalars with its text cleaned, to a depth; anything else as null.
	 *
	 * @param mixed $value the value
	 * @param int $depth levels of nesting still allowed
	 * @return mixed the value to return
	 */
	private static function plain(mixed $value, int $depth): mixed {
		if (is_string($value)) {
			return TablesText::clean($value);
		}
		if (is_scalar($value) || $value === null) {
			return $value;
		}
		if (is_array($value) && $depth > 0) {
			return array_map(static fn (mixed $item): mixed => self::plain($item, $depth - 1), $value);
		}
		return null;
	}
}
