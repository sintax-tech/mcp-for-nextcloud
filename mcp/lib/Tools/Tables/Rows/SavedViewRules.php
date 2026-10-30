<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * The saved filter and sort of a view (and the saved sort of a table, 2.2.2 on), checked before they reach the engine:
 * - the stored JSON is read as it is (View::getFilter(), getSort(), Table::getSort()) and must be well formed: a list of
 *   non-empty groups of conditions with an integer column, an operator and a value, or a list of {columnId, mode};
 * - the arrays Tables makes of it (getFilterArray(), getSortArray()) must hold exactly the same rules: Tables 1.0.0 drops
 *   conditions without a column and empty groups in silence, which would widen the view, so any difference refuses;
 * - every positive column must belong to the table of the target, checked with ColumnMapper::find()->getTableId() (a
 *   metadata read done only after the native row permission, which needs no read access to the parent table); a column
 *   the view does not show is fine, a column of another table, a missing one or a meta column outside -1…-5 refuses;
 * - at most 16 groups of 16 conditions and 8 sort rules.
 * A refusal says nothing of the rule, the column or its value. The checked filter is returned as it is stored, values
 * and placeholders included, for the engine to apply with the caller's id. The columns the rules name, hidden ones
 * included, are kept as Column::jsonSerialize() gives them ({@see self::referencedColumns()}), only for the cursor's
 * state digest: their type, options and defaults change what the engine matches (a missing cell counts as the default).
 */
final class SavedViewRules {
	/** Most groups of a saved filter. */
	public const MAX_GROUPS = 16;

	/** Most conditions in one group of a saved filter. */
	public const MAX_CONDITIONS = 16;

	/** Most saved sort rules. */
	public const MAX_SORT = 8;

	/** Operators of the engine, every supported release together. */
	private const OPERATORS = ['begins-with', 'ends-with', 'contains', 'does-not-contain', 'is-equal', 'is-not-equal', 'is-greater-than',
		'is-greater-than-or-equal', 'is-lower-than', 'is-lower-than-or-equal', 'is-empty', 'is-not-empty', 'contains-item'];

	/** @var array<int, array<string, mixed>> the columns the checked rules name, by id */
	private array $referenced = [];

	/**
	 * Receives the column mapper and the table of the target.
	 *
	 * @param object $columnMapper OCA\Tables\Db\ColumnMapper
	 * @param int $tableId the table of the target
	 * @return void
	 */
	public function __construct(
		private object $columnMapper,
		private int $tableId,
	) {
	}

	/**
	 * The saved filter.
	 *
	 * @param string|null $raw the stored JSON, null or empty without a filter
	 * @param \Closure(): array<mixed> $normalized getFilterArray(), called only once the stored JSON is checked
	 * @return RowCondition TRUE without a filter, else the groups as stored
	 * @throws RowsRefusal when the filter cannot be applied safely
	 */
	public function filter(?string $raw, \Closure $normalized): RowCondition {
		$groups = self::decode($raw);
		if ($groups === []) {
			if (self::call($normalized) !== []) {
				throw RowsRefusal::savedRulesUnusable();
			}
			return RowCondition::true();
		}
		if (count($groups) > self::MAX_GROUPS) {
			throw RowsRefusal::savedRulesUnusable();
		}
		$checked = [];
		foreach ($groups as $group) {
			if (!is_array($group) || $group === [] || !array_is_list($group) || count($group) > self::MAX_CONDITIONS) {
				throw RowsRefusal::savedRulesUnusable();
			}
			$conditions = [];
			foreach ($group as $condition) {
				$conditions[] = $this->condition($condition);
			}
			$checked[] = $conditions;
		}
		if (self::canonical($checked) !== self::canonical(self::normalizedFilter(self::call($normalized)))) {
			throw RowsRefusal::savedRulesUnusable();
		}
		return RowCondition::of($checked);
	}

	/**
	 * The saved sort.
	 *
	 * @param string|null $raw the stored JSON, null or empty without a sort
	 * @param \Closure(): array<mixed> $normalized getSortArray(), called only once the stored JSON is checked
	 * @return list<array{columnId:int, mode:string}> the rules
	 * @throws RowsRefusal when the sort cannot be applied safely
	 */
	public function sort(?string $raw, \Closure $normalized): array {
		$rules = self::decode($raw);
		if (count($rules) > self::MAX_SORT) {
			throw RowsRefusal::savedRulesUnusable();
		}
		$checked = [];
		foreach ($rules as $rule) {
			if (!is_array($rule) || !isset($rule['columnId'], $rule['mode']) || !is_int($rule['columnId'])
				|| !in_array($rule['mode'], ['ASC', 'DESC'], true)) {
				throw RowsRefusal::savedRulesUnusable();
			}
			$this->column($rule['columnId']);
			$checked[] = ['columnId' => $rule['columnId'], 'mode' => $rule['mode']];
		}
		$fromTables = array_map(static fn (mixed $rule): array => is_array($rule)
			? ['columnId' => (int)($rule['columnId'] ?? 0), 'mode' => (string)($rule['mode'] ?? '')] : [], array_values(self::call($normalized)));
		if ($checked !== $fromTables) {
			throw RowsRefusal::savedRulesUnusable();
		}
		return $checked;
	}

	/**
	 * One stored condition, checked.
	 *
	 * @param mixed $condition the stored condition
	 * @return array{columnId:int, operator:string, value:string|array<mixed>} the condition
	 * @throws RowsRefusal when it is malformed or names a column outside the table
	 */
	private function condition(mixed $condition): array {
		if (!is_array($condition) || !isset($condition['columnId'], $condition['operator']) || !array_key_exists('value', $condition)
			|| !is_int($condition['columnId']) || !in_array($condition['operator'], self::OPERATORS, true)
			|| !(is_scalar($condition['value']) || is_array($condition['value'])) || is_bool($condition['value'])) {
			throw RowsRefusal::savedRulesUnusable();
		}
		$this->column($condition['columnId']);
		$value = is_array($condition['value']) ? $condition['value'] : (string)$condition['value'];
		return ['columnId' => $condition['columnId'], 'operator' => $condition['operator'], 'value' => $value];
	}

	/**
	 * Checks that a column of a saved rule belongs to the table of the target, or is a meta column.
	 *
	 * @param int $columnId the column
	 * @return void
	 * @throws RowsRefusal when it does not
	 */
	private function column(int $columnId): void {
		if ($columnId < 0) {
			if (!in_array($columnId, RowFilterDomain::META_COLUMNS, true)) {
				throw RowsRefusal::savedRulesUnusable();
			}
			return;
		}
		try {
			$column = $this->columnMapper->find($columnId);
			$tableId = (int)$column->getTableId();
		} catch (\Throwable) {
			throw RowsRefusal::savedRulesUnusable();
		}
		if ($columnId === 0 || $tableId !== $this->tableId) {
			throw RowsRefusal::savedRulesUnusable();
		}
		try {
			$this->referenced[$columnId] = (array)$column->jsonSerialize();
		} catch (\Throwable) {
			throw RowsRefusal::savedRulesUnusable();
		}
	}

	/**
	 * The columns the checked rules name, in id order, for the state digest only; never part of an answer.
	 *
	 * @return list<array<string, mixed>> the serialized columns
	 */
	public function referencedColumns(): array {
		ksort($this->referenced);
		return array_values($this->referenced);
	}

	/**
	 * What Tables makes of the stored rules; its own refusal of malformed rules refuses too.
	 *
	 * @param \Closure(): array<mixed> $normalized getFilterArray() or getSortArray()
	 * @return array<mixed> the rules
	 * @throws RowsRefusal when Tables cannot read them
	 */
	private static function call(\Closure $normalized): array {
		try {
			$rules = $normalized();
		} catch (\Throwable) {
			throw RowsRefusal::savedRulesUnusable();
		}
		return is_array($rules) ? $rules : throw RowsRefusal::savedRulesUnusable();
	}

	/**
	 * The stored JSON as a list.
	 *
	 * @param string|null $raw the stored JSON
	 * @return list<mixed> the decoded list, empty for no rules
	 * @throws RowsRefusal when it is not a JSON list
	 */
	private static function decode(?string $raw): array {
		if ($raw === null || trim($raw) === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw RowsRefusal::savedRulesUnusable();
		}
		if (!is_array($decoded) || !array_is_list($decoded)) {
			throw RowsRefusal::savedRulesUnusable();
		}
		return $decoded;
	}

	/**
	 * The filter Tables made, in the shape of the checked one.
	 *
	 * @param array<mixed> $normalized what getFilterArray() returned
	 * @return list<list<array{columnId:int, operator:string, value:mixed}>> the filter
	 */
	private static function normalizedFilter(array $normalized): array {
		return array_map(static fn (mixed $group): array => array_map(static fn (mixed $condition): array => is_array($condition) ? [
			'columnId' => (int)($condition['columnId'] ?? 0),
			'operator' => (string)($condition['operator'] ?? ''),
			'value' => is_array($condition['value'] ?? null) ? $condition['value'] : (string)($condition['value'] ?? ''),
		] : [], array_values(is_array($group) ? $group : [])), array_values($normalized));
	}

	/**
	 * A structural form of a filter, independent of key order and of the scalar type of values.
	 *
	 * @param list<list<array{columnId:int, operator:string, value:mixed}>> $filter the filter
	 * @return string the canonical form
	 */
	private static function canonical(array $filter): string {
		return json_encode(array_map(static fn (array $group): array => array_map(static fn (array $condition): array => [
			$condition['columnId'], $condition['operator'], $condition['value'],
		], $group), $filter), JSON_THROW_ON_ERROR);
	}
}
