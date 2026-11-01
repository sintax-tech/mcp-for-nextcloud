<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\TablesMessages;

/**
 * The arguments of tables_list_rows, already checked against {@see self::schema()} by the registry, read into one value:
 * exactly one target, the user's filter as groups of raw conditions (column ids are checked later, against the columns the
 * user can see), the search, the sort, the page size and the cursor. A present but empty filter, an empty group or a sort
 * on the same column twice is an invalid call, never read as "no restriction".
 */
final class RowsRequest {
	/** Most groups of the user's filter. */
	public const MAX_GROUPS = 8;

	/** Most conditions in one group of the user's filter. */
	public const MAX_CONDITIONS = 8;

	/** Most sort rules. */
	public const MAX_SORT = 3;

	/** Default page size. */
	public const DEFAULT_LIMIT = 25;

	/** Largest page size. */
	public const MAX_LIMIT = 100;

	/** Longest search. */
	public const MAX_SEARCH = 100;

	/**
	 * Receives the read arguments.
	 *
	 * @param string $targetType "table" or "view"
	 * @param int $targetId table or view id
	 * @param list<list<array{column_id:int, operator:string, value:mixed}>>|null $filter the user's filter, null when absent
	 * @param string|null $search the search, null when absent
	 * @param list<array{column_id:int, mode:string}> $sort the user's sort, empty when absent
	 * @param int $limit page size
	 * @param string|null $cursor the cursor of the previous page, null for the first page
	 * @return void
	 */
	private function __construct(
		public readonly string $targetType,
		public readonly int $targetId,
		public readonly ?array $filter,
		public readonly ?string $search,
		public readonly array $sort,
		public readonly int $limit,
		public readonly ?string $cursor,
	) {
	}

	/**
	 * Reads the validated arguments.
	 *
	 * @param array<string, mixed> $arguments arguments checked against the schema
	 * @return self the request
	 * @throws ArgumentValidationException for both or neither target, an empty filter or group, or a repeated sort column
	 * @throws RowsRefusal for a search starting with a placeholder sign
	 */
	public static function of(array $arguments): self {
		$tableId = isset($arguments['table_id']) ? (int)$arguments['table_id'] : null;
		$viewId = isset($arguments['view_id']) ? (int)$arguments['view_id'] : null;
		if (($tableId === null) === ($viewId === null)) {
			throw new ArgumentValidationException('Invalid arguments', '', TablesMessages::oneOfTableOrView());
		}
		$filter = null;
		if (array_key_exists('filter', $arguments)) {
			$filter = array_values((array)$arguments['filter']);
			if ($filter === [] || in_array([], $filter, true)) {
				throw new ArgumentValidationException('Invalid argument: filter', 'filter', RowsMessages::invalidQuery());
			}
		}
		$search = isset($arguments['search']) ? trim((string)$arguments['search']) : null;
		if ($search !== null && ($search === '' || str_starts_with($search, '@'))) {
			throw RowsRefusal::invalidQuery();
		}
		$sort = array_values((array)($arguments['sort'] ?? []));
		$columns = array_map(static fn (array $rule): int => (int)$rule['column_id'], $sort);
		if (count($columns) !== count(array_unique($columns))) {
			throw new ArgumentValidationException('Invalid argument: sort', 'sort', RowsMessages::invalidQuery());
		}
		return new self($viewId !== null ? 'view' : 'table', (int)($viewId ?? $tableId), $filter, $search,
			array_map(static fn (array $rule): array => ['column_id' => (int)$rule['column_id'], 'mode' => strtoupper((string)$rule['mode'])], $sort),
			(int)($arguments['limit'] ?? self::DEFAULT_LIMIT), isset($arguments['cursor']) ? (string)$arguments['cursor'] : null);
	}

	/**
	 * The arguments of the tool, as the registry validates them.
	 *
	 * @return array<string, mixed> the properties
	 */
	public static function schema(): array {
		$condition = [
			'type' => 'object',
			'properties' => [
				'column_id' => ['type' => 'integer', 'minimum' => -5, 'description' => 'Column id from tables_list_columns, or a meta column (-1 id, -2 created by, -3 edited by, -4 created at, -5 edited at).'],
				'operator' => ['type' => 'string', 'enum' => ['begins-with', 'ends-with', 'contains', 'does-not-contain', 'is-equal', 'is-not-equal',
					'is-greater-than', 'is-greater-than-or-equal', 'is-lower-than', 'is-lower-than-or-equal', 'is-empty', 'is-not-empty']],
				'value' => ['type' => ['string', 'number', 'integer'], 'description' => 'The value; a selection option id for a selection column; omit it for is-empty and is-not-empty.'],
			],
			'required' => ['column_id', 'operator'],
			'additionalProperties' => false,
		];
		return [
			'table_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the table, as tables_list_tables returns it. Give this or view_id, not both.'],
			'view_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the view, as tables_list_views returns it. Give this or table_id, not both.'],
			'filter' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_GROUPS,
				'description' => 'Groups OR-ed together; the conditions inside a group are AND-ed. Always combined with the view\'s own filter.',
				'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CONDITIONS, 'items' => $condition]],
			'search' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_SEARCH,
				'description' => 'Text searched in the text columns and the selection labels the user can see.'],
			'sort' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_SORT, 'items' => [
				'type' => 'object',
				'properties' => [
					'column_id' => ['type' => 'integer', 'minimum' => -5],
					'mode' => ['type' => 'string', 'enum' => ['ASC', 'DESC', 'asc', 'desc']],
				],
				'required' => ['column_id', 'mode'],
				'additionalProperties' => false,
			], 'description' => 'Sort rules in order; without them the view\'s or table\'s own sort applies. The row id always breaks ties.'],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT,
				'description' => 'Rows per page (default 25, at most 100).'],
			'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => RowCursor::MAX_LENGTH,
				'description' => 'nextCursor of the previous page, with the same other arguments.'],
		];
	}
}
