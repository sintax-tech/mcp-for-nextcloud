<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Reading\SavedViewRules;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;

/**
 * Checks, for every creation through a view, the values RowService::enhanceWithViewDefaults() will add after validating
 * the cells, the same in every pinned release. It never sends, changes or overrides them: the native enhancer writes them.
 *
 * - The whole saved filter must pass {@see SavedViewRules} (native form, columns of the view's table).
 * - The selection is the enhancer's, first match wins: the columns present start as the cells' columns; groups and rules
 *   are walked in the native order of View::getFilterArray(); a rule with a value, operator is-equal or is-not-equal, on a
 *   column the view does not show, not a meta column and not present yet is selected, and its column becomes present at
 *   once, so later rules for it are ignored (RowDataInput::hasColumn()).
 * - The value of a selected rule is ColumnsHelper::resolveSearchValue($value, $userId), the enhancer's two-argument call,
 *   asked of the native helper only to check it. It goes to the cell mapper without the parser, so it must already be a
 *   raw form the mapper keeps: text within its limits, a numeric string or stars or progress within the subtype's range and
 *   the column's limits ({@see NumberBounds}, which Tables never applies to these hidden values), the
 *   id string of an existing option, "true"/"false", an exact date or time, or a link JSON with exactly title, value and
 *   providerId "url" and an http(s) url. Multiple selections, users/groups and relations are refused: the enhancer hands
 *   them a raw string, never the list or checked id they need.
 * Any failure refuses with one fixed message that names no column, rule or value. The resolved list goes into the plan
 * state only, never into an answer.
 */
class ViewDefaultsGate {
	/** Operators the enhancer turns into values. */
	private const OPERATORS = ['is-equal', 'is-not-equal'];

	/**
	 * Receives the Tables services.
	 *
	 * @param TablesServices $services resolver of the Tables services
	 * @return void
	 */
	public function __construct(
		private TablesServices $services,
	) {
	}

	/**
	 * The values the enhancer will add, checked.
	 *
	 * @param RowWriteTarget $target a view target
	 * @param list<int> $cellColumns the columns of the cells being written
	 * @param string $userId authenticated user UID
	 * @return array{defaults: list<array{0:int, 1:string}>, referenced: list<array<string, mixed>>} the selected columns
	 *   with their resolved values, in the enhancer's order, and the columns the saved filter names
	 * @throws RowWriteRefusal when a saved rule or a value cannot be checked
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function resolve(RowWriteTarget $target, array $cellColumns, string $userId): array {
		$view = $target->view;
		if ($view === null) {
			return ['defaults' => [], 'referenced' => []];
		}
		$helper = $this->services->columnsHelper($userId);
		try {
			$referenced = [];
			foreach ($this->referenced($target, $userId) as $column) {
				$referenced[(int)$column['id']] = $column;
			}
			$present = array_fill_keys($cellColumns, true);
			$visible = array_map('intval', (array)$view->getColumnIds());
			$defaults = [];
			foreach ((array)$view->getFilterArray() as $group) {
				foreach (is_array($group) ? $group : [] as $rule) {
					if (!is_array($rule) || !isset($rule['columnId'], $rule['operator'], $rule['value'])) {
						continue;
					}
					$columnId = (int)$rule['columnId'];
					if (in_array($columnId, $visible, true) || $columnId < 0 || !in_array($rule['operator'], self::OPERATORS, true)
						|| isset($present[$columnId])) {
						continue;
					}
					$column = $referenced[$columnId] ?? throw RowWriteRefusal::viewDefaults();
					$value = $helper->resolveSearchValue((string)$rule['value'], $userId);
					if (!is_string($value) || !self::isRawEffect($column, $value)) {
						throw RowWriteRefusal::viewDefaults();
					}
					$present[$columnId] = true;
					$defaults[] = [$columnId, $value];
				}
			}
		} catch (RowWriteRefusal|TablesUnavailableException $e) {
			throw $e;
		} catch (\Throwable) {
			throw RowWriteRefusal::viewDefaults();
		}
		return ['defaults' => $defaults, 'referenced' => array_values($referenced)];
	}

	/**
	 * The columns the view's saved filter and sort name, hidden ones included, once both pass {@see SavedViewRules}; for the
	 * plan state of every write through a view. A saved filter or sort that cannot be read safely refuses the write, as it
	 * refuses a read of the view's rows.
	 *
	 * @param RowWriteTarget $target a view target
	 * @param string $userId authenticated user UID
	 * @return list<array<string, mixed>> the columns as Column::jsonSerialize() gives them, in id order
	 * @throws RowWriteRefusal when the saved filter cannot be applied safely
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function referenced(RowWriteTarget $target, string $userId): array {
		$view = $target->view;
		if ($view === null) {
			return [];
		}
		$rules = new SavedViewRules($this->services->columnMapper($userId), $target->tableId);
		try {
			$rules->filter(is_string($view->getFilter()) && $view->getFilter() !== '' ? $view->getFilter() : null, static fn (): array => (array)$view->getFilterArray());
			$rules->sort(is_string($view->getSort()) && $view->getSort() !== '' ? $view->getSort() : null, static fn (): array => (array)$view->getSortArray());
		} catch (TablesUnavailableException $e) {
			throw $e;
		} catch (\Throwable) {
			throw RowWriteRefusal::viewDefaults();
		}
		return $rules->referencedColumns();
	}

	/**
	 * Whether a resolved value is a raw form the cell mapper of the column keeps, with a known effect.
	 *
	 * @param array<string, mixed> $column the column as Column::jsonSerialize() gives it
	 * @param string $value the resolved value
	 * @return bool true when it is
	 */
	public static function isRawEffect(array $column, string $value): bool {
		$type = (string)($column['type'] ?? '');
		$subtype = (string)($column['subtype'] ?? '');
		return match ([$type, $subtype]) {
			['text', ''], ['text', 'line'], ['text', 'long'], ['text', 'rich'] => CellValueDomain::fitsText($column, $value),
			['text', 'link'] => CellValueDomain::isCanonicalLink($column, $value),
			['number', ''] => is_numeric($value) && NumberBounds::fits($column, (float)$value),
			['number', 'stars'], ['number', 'progress'] => preg_match('/^\d{1,3}$/', $value) === 1 && NumberBounds::fits($column, (int)$value),
			['selection', ''] => preg_match('/^\d{1,18}$/', $value) === 1 && in_array((int)$value, CellValueDomain::optionIds($column), true),
			['selection', 'check'] => $value === 'true' || $value === 'false',
			['datetime', ''] => CellValueDomain::isExactDatetime($value, 'Y-m-d H:i'),
			['datetime', 'date'] => CellValueDomain::isExactDatetime($value, 'Y-m-d'),
			['datetime', 'time'] => CellValueDomain::isExactDatetime($value, 'H:i'),
			default => false,
		};
	}
}
