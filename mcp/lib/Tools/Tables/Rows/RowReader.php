<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\Tables\Columns\ColumnLookup;
use OCA\Mcp\Tools\Tables\TablesCalls;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesServices;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Reads one page of rows the way RowService::findAllByView()/findAllByTable() does, with the user's filter, search and
 * sort added, every page in this order:
 * 1. an authenticated user that is not empty (Tables grants everything to an empty user id);
 * 2. PermissionsService::canReadRowsByElementId() for the target and the user, the native authority for owner, shares,
 *    manage of the parent table and applications (contexts);
 * 3. the current state: the view or table, the columns the user can see ({@see ColumnLookup}), the saved filter and sort;
 * 4. the saved rules checked ({@see SavedViewRules}), the user's conditions checked against the visible columns
 *    ({@see RowFilterDomain}), the search translated ({@see RowSearch}), all composed ({@see RowFilterComposer}) and the
 *    sort made total ({@see RowSort});
 * 5. the cursor checked against this query and state ({@see RowCursor});
 * 6. the session checked to be the caller's ({@see TablesSessionGuard});
 * 7. Row2Mapper::findAll() with the visible columns, limit + 1, the offset, the composed filter, the sort and the user id;
 * 8. the rows shaped ({@see RowShape}) within the text budget of the page ({@see RowCellText}), the extra row only
 *    telling whether a next page exists.
 * A FALSE condition or no visible column returns no row without calling the engine. A next page past the largest
 * offset a cursor may carry ({@see self::maxOffset()}) gets no cursor: the page says `offsetLimitReached` and keeps
 * `hasMore`, since rows remain that a narrower filter can reach.
 */
class RowReader {
	/** Tool the cursor belongs to. */
	public const TOOL = 'tables_list_rows';

	/**
	 * Receives the Tables services, the column lookup, the session guard and the MAC.
	 *
	 * @param TablesServices $services resolver of the Tables services
	 * @param ColumnLookup $columns the columns a user can see
	 * @param TablesSessionGuard $session checks the session is the caller's
	 * @param PlanState $mac the instance MAC for cursors
	 * @return void
	 */
	public function __construct(
		private TablesServices $services,
		private ColumnLookup $columns,
		private TablesSessionGuard $session,
		private PlanState $mac,
	) {
	}

	/**
	 * One page of rows.
	 *
	 * @param RowsRequest $request the read arguments
	 * @param string $userId authenticated user UID
	 * @return array{rows:list<array<string, mixed>>, hasMore:bool, nextCursor:string|null, offsetLimitReached:bool, truncatedCells:int} the page
	 * @throws ToolFailure when the target is not the user's to read, the Tables app failed, or the query is refused
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function page(RowsRequest $request, string $userId): array {
		if ($userId === '') {
			throw RowsRefusal::sessionMismatch();
		}
		$permissions = $this->services->permissionsService($userId);
		$type = $request->targetType;
		$id = $request->targetId;
		if (!TablesCalls::run(static fn (): bool => (bool)$permissions->canReadRowsByElementId($id, $type, $userId))) {
			throw new ToolFailure(TablesMessages::notFound());
		}
		$version = $this->services->version();
		[$tableId, $projection, $savedFilter, $savedSort, $filterArray, $sortArray] = $this->state($request, $userId, $version);
		foreach ($projection as $column) {
			if ((int)($column['tableId'] ?? 0) !== $tableId) {
				throw RowsRefusal::savedRulesUnusable();
			}
		}
		$rules = new SavedViewRules($this->services->columnMapper($userId), $tableId);
		$saved = $rules->filter($savedFilter, $filterArray);
		$sortSaved = $rules->sort($savedSort, $sortArray);
		$visible = array_fill_keys(array_map(static fn (array $column): int => (int)$column['id'], $projection), true);
		$condition = RowFilterComposer::compose([$saved, $this->userFilter($request, $projection, $version), RowSearch::of($request->search, $projection)]);
		$sort = RowSort::of($request->sort, $visible, $sortSaved);
		$cursor = new RowCursor($this->mac, self::TOOL, $userId, $this->maxOffset(), [
			'tables' => $version,
			'target' => [$type, $id],
			'table' => $tableId,
			'limit' => $request->limit,
			'query' => [$request->filter, $request->search, $request->sort],
			'state' => RowStateDigest::of($tableId, $projection, $rules->referencedColumns(), $savedFilter, $savedSort),
		]);
		$offset = $cursor->offset($request->cursor);
		if ($projection === [] || $condition->isFalse()) {
			return ['rows' => [], 'hasMore' => false, 'nextCursor' => null, 'offsetLimitReached' => false, 'truncatedCells' => 0];
		}
		$this->session->assertCaller($userId);
		$mapper = $this->services->rowMapper($userId);
		$filter = $condition->isTrue() ? null : $condition->branches();
		$columnIds = array_keys($visible);
		$limit = $request->limit + 1;
		$rows = TablesCalls::run(static fn (): array => array_values($mapper->findAll($columnIds, $tableId, $limit, $offset, $filter, $sort, $userId)));
		$hasMore = count($rows) > $request->limit;
		$next = $offset + $request->limit;
		$reachable = $hasMore && $next <= $this->maxOffset();
		$text = new RowCellText();
		$shaped = array_map(static fn (object $row): array => RowShape::of($row, $visible, $text), array_slice($rows, 0, $request->limit));
		return [
			'rows' => $shaped,
			'hasMore' => $hasMore,
			'nextCursor' => $reachable ? $cursor->encode($next) : null,
			'offsetLimitReached' => $hasMore && !$reachable,
			'truncatedCells' => $text->cutCells(),
		];
	}

	/**
	 * The largest offset a cursor may carry, the same for the cursors made and the cursors read.
	 *
	 * @return int the offset
	 */
	protected function maxOffset(): int {
		return RowCursor::MAX_OFFSET;
	}

	/**
	 * The current state of the target.
	 *
	 * @param RowsRequest $request the read arguments
	 * @param string $userId authenticated user UID
	 * @param string $version installed Tables version
	 * @return array{0:int, 1:list<array<string, mixed>>, 2:string|null, 3:string|null, 4:\Closure, 5:\Closure} table, visible columns, stored filter and sort, and the native arrays
	 * @throws ToolFailure when the target is gone or the Tables app failed
	 */
	private function state(RowsRequest $request, string $userId, string $version): array {
		$id = $request->targetId;
		if ($request->targetType === 'view') {
			$views = $this->services->viewService($userId);
			$view = TablesCalls::run(static fn (): object => $views->find($id, true, $userId));
			$projection = self::serialized($this->columns->forView($userId, $id));
			return [(int)$view->getTableId(), $projection, self::text($view->getFilter()), self::text($view->getSort()),
				static fn (): array => (array)$view->getFilterArray(), static fn (): array => (array)$view->getSortArray()];
		}
		$projection = self::serialized($this->columns->forTable($userId, $id));
		if (version_compare($version, '2.2.2', '<')) {
			return [$id, $projection, null, null, static fn (): array => [], static fn (): array => []];
		}
		$tables = $this->services->tableService($userId);
		$table = TablesCalls::run(static fn (): object => $tables->find($id, true, $userId));
		return [$id, $projection, null, self::text($table->getSort()), static fn (): array => [], static fn (): array => (array)$table->getSortArray()];
	}

	/**
	 * The user's filter as a condition on the visible columns and the meta columns.
	 *
	 * @param RowsRequest $request the read arguments
	 * @param list<array<string, mixed>> $projection the visible columns
	 * @param string $version installed Tables version
	 * @return RowCondition TRUE without a filter, else the checked groups
	 * @throws RowsRefusal when a condition is outside the domain or names a column the user cannot see
	 */
	private function userFilter(RowsRequest $request, array $projection, string $version): RowCondition {
		if ($request->filter === null) {
			return RowCondition::true();
		}
		$byId = [];
		foreach ($projection as $column) {
			$byId[(int)$column['id']] = $column;
		}
		$domain = new RowFilterDomain($version);
		$groups = [];
		foreach ($request->filter as $group) {
			$conditions = [];
			foreach ($group as $condition) {
				$columnId = (int)$condition['column_id'];
				$column = $byId[$columnId] ?? null;
				if ($column === null && !in_array($columnId, RowFilterDomain::META_COLUMNS, true)) {
					throw RowsRefusal::invalidQuery();
				}
				$conditions[] = $domain->condition($columnId, $column, (string)$condition['operator'], $condition['value'] ?? null);
			}
			$groups[] = $conditions;
		}
		return RowCondition::of($groups);
	}

	/**
	 * Columns as Column::jsonSerialize() gives them.
	 *
	 * @param list<object> $columns OCA\Tables\Db\Column objects
	 * @return list<array<string, mixed>> the serialized columns
	 */
	private static function serialized(array $columns): array {
		return array_map(static fn (object $column): array => (array)$column->jsonSerialize(), $columns);
	}

	/**
	 * A stored rule as text, null when there is none.
	 *
	 * @param mixed $value the stored value
	 * @return string|null the text
	 */
	private static function text(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}
}
