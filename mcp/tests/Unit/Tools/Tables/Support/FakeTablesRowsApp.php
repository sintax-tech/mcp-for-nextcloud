<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The Tables app in memory of {@see FakeTablesApp}, with rows: stored rows per table, the saved filter and sort of views
 * (as stored JSON and as the arrays Tables makes of it), the saved sort of tables, shares that allow creating but not
 * reading rows, and strict doubles of PermissionsService::canReadRowsByElementId(), Row2Mapper::findAll() and
 * ColumnMapper::find(). The row mapper records its arguments and returns the stored rows of the table in id order,
 * sliced by limit and offset: what it filters is the engine's business, proven against the real Tables in the cells.
 */
final class FakeTablesRowsApp extends FakeTablesApp {
	/** The installed Tables version. */
	public string $tablesVersion = '2.3.2';

	/** @var array<int, array<string, mixed>> each column as stored, its table included */
	private array $columnTables = [];

	/** @var array<int, list<array<string, mixed>>> stored rows by table */
	private array $rows = [];

	/** @var array<int, array{filter:?string, sort:?string, filterArray?:array<mixed>, sortArray?:array<mixed>}> saved rules by view */
	private array $viewRules = [];

	/** @var array<int, ?string> saved sort by table */
	private array $tableSort = [];

	/** @var array<string, array<int, true>> tables a user may create rows in but not read, by user */
	private array $createOnly = [];

	/**
	 * Stores a column, remembering it for the column mapper too.
	 *
	 * @param array<string, mixed> $fields fields as Column::jsonSerialize() returns them; id and tableId required
	 * @return self this app
	 */
	public function withColumn(array $fields): self {
		$this->columnTables[(int)$fields['id']] = $fields;
		parent::withColumn($fields);
		return $this;
	}

	/**
	 * Stores a row.
	 *
	 * @param int $tableId its table
	 * @param int $id row id
	 * @param array<int, mixed> $cells values by column id
	 * @return self this app
	 */
	public function withRow(int $tableId, int $id, array $cells): self {
		$this->rows[$tableId][$id] = ['id' => $id, 'tableId' => $tableId, 'createdBy' => 'alice', 'createdAt' => '2026-10-01 10:00:00',
			'lastEditBy' => 'alice', 'lastEditAt' => '2026-10-02 10:00:00', 'cells' => $cells];
		ksort($this->rows[$tableId]);
		return $this;
	}

	/**
	 * Saves the filter and sort of a view, as stored JSON; the arrays Tables makes of them default to the decoded JSON.
	 *
	 * @param int $viewId the view
	 * @param string|null $filter stored filter
	 * @param string|null $sort stored sort
	 * @param array<mixed>|null $filterArray what getFilterArray() returns, when it differs from the JSON
	 * @return self this app
	 */
	public function withViewRules(int $viewId, ?string $filter, ?string $sort, ?array $filterArray = null): self {
		$this->viewRules[$viewId] = ['filter' => $filter, 'sort' => $sort, 'filterArray' => $filterArray];
		return $this;
	}

	/**
	 * Saves the sort of a table.
	 *
	 * @param int $tableId the table
	 * @param string|null $sort stored sort
	 * @return self this app
	 */
	public function withTableSort(int $tableId, ?string $sort): self {
		$this->tableSort[$tableId] = $sort;
		return $this;
	}

	/**
	 * Lets a user create rows in a table without reading them.
	 *
	 * @param int $tableId the table
	 * @param string $userId the user
	 * @return self this app
	 */
	public function creatingOnly(int $tableId, string $userId): self {
		$this->createOnly[$userId][$tableId] = true;
		parent::sharingTable($tableId, $userId);
		return $this;
	}

	/**
	 * The installed version.
	 *
	 * @return string the version the test set
	 */
	public function version(): string {
		return $this->tablesVersion;
	}

	/**
	 * Resolves the Tables services, the row-reading ones decorated.
	 *
	 * @param string $class fully qualified Tables class name
	 * @param string $userId authenticated user UID
	 * @return object the double
	 */
	protected function resolve(string $class, string $userId): object {
		return match ($class) {
			'OCA\\Tables\\Service\\PermissionsService' => $this->fakePermissions(parent::resolve($class, $userId), parent::resolve('OCA\\Tables\\Service\\ViewService', $userId)),
			'OCA\\Tables\\Service\\ViewService' => $this->fakeViews(parent::resolve($class, $userId)),
			'OCA\\Tables\\Service\\TableService' => $this->tablesWithSort(),
			'OCA\\Tables\\Db\\Row2Mapper' => $this->fakeRowMapper(),
			'OCA\\Tables\\Db\\ColumnMapper' => $this->columnMapperDouble(),
			default => parent::resolve($class, $userId),
		};
	}

	/**
	 * PermissionsService with canReadRowsByElementId(): for a table, who reads its columns except a share that only allows
	 * creating rows; for a view, who opens it.
	 *
	 * @param object $base the PermissionsService double of the parent
	 * @param object $views the ViewService double of the parent
	 * @return TablesDouble the double
	 */
	private function fakePermissions(object $base, object $views): TablesDouble {
		return new TablesDouble('PermissionsService', [
			'canReadColumnsByTableId' => static fn (int $tableId, ?string $userId = null): bool => $base->canReadColumnsByTableId($tableId, $userId),
			'canManageTable' => static fn (object $table, ?string $userId = null): bool => $base->canManageTable($table, $userId),
			'canAccessView' => static fn (object $view, ?string $userId = null): bool => $base->canAccessView($view, $userId),
			'canReadRowsByElementId' => function (int $id, string $type, ?string $userId = null) use ($base, $views): bool {
				if ($type === 'table') {
					return !isset($this->createOnly[(string)$userId][$id]) && $base->canReadColumnsByTableId($id, $userId);
				}
				try {
					$view = $views->find($id, true, $userId);
				} catch (\Throwable) {
					return false;
				}
				return (bool)$base->canAccessView($view, $userId);
			},
		], $this->journal);
	}

	/**
	 * ViewService whose views also answer their saved rules.
	 *
	 * @param object $base the ViewService double of the parent
	 * @return TablesDouble the double
	 */
	private function fakeViews(object $base): TablesDouble {
		$decorate = fn (object $view): TablesDouble => $this->viewWithRules($view);
		return new TablesDouble('ViewService', [
			'findAll' => static fn (object $table, ?string $userId = null): array => array_map($decorate, $base->findAll($table, $userId)),
			'find' => static fn (int $id, bool $skip = false, ?string $userId = null): TablesDouble => $decorate($base->find($id, $skip, $userId)),
			'findSharedViewsWithMe' => static fn (?string $userId = null): array => array_map($decorate, $base->findSharedViewsWithMe($userId)),
		], $this->journal);
	}

	/**
	 * A view double that also answers getFilter(), getSort(), getFilterArray() and getSortArray().
	 *
	 * @param object $view the view double of the parent
	 * @return TablesDouble the double
	 */
	private function viewWithRules(object $view): TablesDouble {
		$id = (int)$view->getId();
		$rules = $this->viewRules[$id] ?? ['filter' => null, 'sort' => null, 'filterArray' => null];
		$decode = static fn (?string $json): array => $json === null || $json === '' ? [] : (array)json_decode($json, true);
		return new TablesDouble("View#$id", [
			'getId' => static fn (): int => $id,
			'getTableId' => static fn (): int => (int)$view->getTableId(),
			'getTitle' => static fn (): string => $view->getTitle(),
			'getEmoji' => static fn (): ?string => $view->getEmoji(),
			'getDescription' => static fn (): ?string => $view->getDescription(),
			'getColumnIds' => static fn (): array => $view->getColumnIds(),
			'getFilter' => static fn (): ?string => $rules['filter'],
			'getSort' => static fn (): ?string => $rules['sort'],
			'getFilterArray' => static fn (): array => $rules['filterArray'] ?? $decode($rules['filter']),
			'getSortArray' => static fn (): array => $decode($rules['sort']),
		], $this->journal);
	}

	/**
	 * TableService answering find() with a table that has its saved sort.
	 *
	 * @return TablesDouble the double
	 */
	private function tablesWithSort(): TablesDouble {
		return new TablesDouble('TableService', [
			'find' => function (int $id, bool $skip = false, ?string $userId = null): TablesDouble {
				$sort = $this->tableSort[$id] ?? null;
				return new TablesDouble("Table#$id", [
					'getId' => static fn (): int => $id,
					'getSort' => static fn (): ?string => $sort,
					'getSortArray' => static fn (): array => $sort === null ? [] : (array)json_decode($sort, true),
				], $this->journal);
			},
		], $this->journal);
	}

	/**
	 * Row2Mapper answering findAll() with the stored rows of the table, in id order, sliced.
	 *
	 * @return TablesDouble the double
	 */
	private function fakeRowMapper(): TablesDouble {
		return new TablesDouble('Row2Mapper', [
			'findAll' => function (array $columnIds, int $tableId, ?int $limit = null, ?int $offset = null, ?array $filter = null, ?array $sort = null, ?string $userId = null): array {
				$rows = array_slice(array_values($this->rows[$tableId] ?? []), (int)$offset, $limit);
				return array_map(fn (array $row): TablesDouble => $this->row($row, $columnIds), $rows);
			},
		], $this->journal);
	}

	/**
	 * A strict double of OCA\Tables\Db\Row2 holding only the cells of the given columns, as the engine reads them.
	 *
	 * @param array<string, mixed> $row the stored row
	 * @param list<int> $columnIds the columns asked for
	 * @return TablesDouble the double
	 */
	private function row(array $row, array $columnIds): TablesDouble {
		$data = [];
		foreach ($row['cells'] as $columnId => $value) {
			if (in_array($columnId, $columnIds, true)) {
				$data[] = ['columnId' => $columnId, 'value' => $value];
			}
		}
		$serialized = ['id' => $row['id'], 'tableId' => $row['tableId'], 'createdBy' => $row['createdBy'], 'createdAt' => $row['createdAt'],
			'lastEditBy' => $row['lastEditBy'], 'lastEditAt' => $row['lastEditAt'], 'data' => $data, 'dataByAlias' => ['alias' => ['columnId' => 0, 'value' => 'x']]];
		return new TablesDouble('Row#' . $row['id'], ['jsonSerialize' => static fn (): array => $serialized], $this->journal);
	}

	/**
	 * ColumnMapper answering find() with a stored column (its table and its serialized fields), or DoesNotExistException.
	 *
	 * @return TablesDouble the double
	 */
	private function columnMapperDouble(): TablesDouble {
		return new TablesDouble('ColumnMapper', [
			'find' => function (int $id): TablesDouble {
				if (!isset($this->columnTables[$id])) {
					throw new DoesNotExistException("Column $id not found");
				}
				$fields = $this->columnTables[$id];
				return new TablesDouble("ColumnEntity#$id", [
					'getTableId' => static fn (): int => (int)$fields['tableId'],
					'jsonSerialize' => static fn (): array => $fields,
				], $this->journal);
			},
		], $this->journal);
	}
}
