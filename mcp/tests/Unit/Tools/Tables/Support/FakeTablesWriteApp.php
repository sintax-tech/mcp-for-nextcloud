<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Tools\Tables\TablesServices;
use OCA\Tables\Errors\BadRequestError;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../Stubs/tables_stubs.php';
require_once __DIR__ . '/../Stubs/tables_write_errors.php';

/**
 * A Tables app for the row write tests, built from strict doubles ({@see TablesDouble}) that journal every call: tables
 * with an owner, columns, views with their column settings and saved rules, rows, and per user and node the permissions
 * read, create and update (the owner of a table has them all, on its views too). RowService behaves like the pinned
 * sources: it parses, then adds the view's hidden values (first match per column, raw), stores each value through the
 * cell mapper (the number mapper of the releases before 2.3.2 stores 0 for a missing number), only then fires its event,
 * and answers the row as it held it in memory, not as stored; `sink` makes it refuse before writing or fail after
 * writing, as a listener can.
 */
final class FakeTablesWriteApp extends TablesServices {
	/** The installed Tables version. */
	public string $tablesVersion = '2.3.2';

	/** @var \ArrayObject<int, array{0:string, 1:string, 2:list<mixed>}> every call to a double */
	public \ArrayObject $journal;

	/** The user RowService takes from the session, for the hidden values it resolves. */
	public string $actor = 'alice';

	/** RowService outcome: 'ok', 'refuse' (before writing) or 'failAfter' (written, then a listener throws). */
	public string $sink = 'ok';

	/** @var \Closure(string, string): (string|array<mixed>) what ColumnsHelper::resolveSearchValue() answers */
	public \Closure $resolveSearch;

	/** @var array<int, array{owner:string, federated:bool}> tables by id */
	private array $tables = [];

	/** @var array<int, array<string, mixed>> columns by id, as Column::jsonSerialize() gives them */
	private array $columns = [];

	/** @var array<int, array{tableId:int, columns:list<int>, settings:array<int, array{readonly:bool, mandatory:bool}>, filter:?string, sort:?string, filterArray:?array, federated:bool}> */
	private array $views = [];

	/** @var array<int, array<int, array{data:array<int, mixed>, lastEditAt:string}>> rows by table and id */
	private array $rows = [];

	/** @var array<string, array<string, array<string, true>>> permissions by user and "type:id" */
	private array $grants = [];

	/**
	 * An empty app.
	 *
	 * @return void
	 */
	public function __construct() {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		parent::__construct($stub(IAppManager::class), $stub(ContainerInterface::class), $stub(IUserManager::class));
		$this->journal = new \ArrayObject();
		$this->resolveSearch = static function (string $placeholder, string $userId): string {
			return match (true) {
				str_starts_with($placeholder, '@selection-id-') => substr($placeholder, 14),
				$placeholder === '@checked' => 'true',
				$placeholder === '@unchecked' => 'false',
				$placeholder === '@me' => $userId,
				default => $placeholder,
			};
		};
	}

	/**
	 * Stores a table.
	 *
	 * @param int $id table id
	 * @param string $owner its owner
	 * @param bool $federated whether it is federated
	 * @return self this app
	 */
	public function withTable(int $id, string $owner, bool $federated = false): self {
		$this->tables[$id] = ['owner' => $owner, 'federated' => $federated];
		return $this;
	}

	/**
	 * Stores a column.
	 *
	 * @param array<string, mixed> $fields fields as Column::jsonSerialize() gives them; id and tableId required
	 * @return self this app
	 */
	public function withColumn(array $fields): self {
		$this->columns[(int)$fields['id']] = $fields + ['type' => 'text', 'subtype' => 'line', 'title' => 'Column ' . $fields['id'], 'mandatory' => false];
		return $this;
	}

	/**
	 * Stores a view.
	 *
	 * @param int $id view id
	 * @param int $tableId its table
	 * @param list<int> $columns the columns it shows
	 * @param array<int, array{readonly?:bool, mandatory?:bool}> $settings settings by column
	 * @param string|null $filter stored filter
	 * @param string|null $sort stored sort
	 * @param array<mixed>|null $filterArray what getFilterArray() returns, when it differs from the decoded filter
	 * @param bool $federated whether it is federated
	 * @return self this app
	 */
	public function withView(int $id, int $tableId, array $columns, array $settings = [], ?string $filter = null, ?string $sort = null, ?array $filterArray = null, bool $federated = false): self {
		$normalized = [];
		foreach ($settings as $columnId => $setting) {
			$normalized[$columnId] = ['readonly' => (bool)($setting['readonly'] ?? false), 'mandatory' => (bool)($setting['mandatory'] ?? false)];
		}
		$this->views[$id] = ['tableId' => $tableId, 'columns' => $columns, 'settings' => $normalized, 'filter' => $filter, 'sort' => $sort,
			'filterArray' => $filterArray, 'federated' => $federated];
		return $this;
	}

	/**
	 * Stores a row.
	 *
	 * @param int $tableId its table
	 * @param int $id row id
	 * @param array<int, mixed> $data values by column id
	 * @param string $lastEditAt its last edit time
	 * @return self this app
	 */
	public function withRow(int $tableId, int $id, array $data, string $lastEditAt = '2026-10-01 10:00:00'): self {
		$this->rows[$tableId][$id] = ['data' => $data, 'lastEditAt' => $lastEditAt];
		ksort($this->rows[$tableId]);
		return $this;
	}

	/**
	 * Gives a user permissions on a table or a view.
	 *
	 * @param string $userId the user
	 * @param string $type 'table' or 'view'
	 * @param int $id the node
	 * @param string ...$permissions read, create and/or update
	 * @return self this app
	 */
	public function grant(string $userId, string $type, int $id, string ...$permissions): self {
		foreach ($permissions as $permission) {
			$this->grants[$userId][$type . ':' . $id][$permission] = true;
		}
		return $this;
	}

	/**
	 * The stored row of a table, for assertions.
	 *
	 * @param int $tableId the table
	 * @param int $id the row
	 * @return array{data:array<int, mixed>, lastEditAt:string}|null the row
	 */
	public function row(int $tableId, int $id): ?array {
		return $this->rows[$tableId][$id] ?? null;
	}

	/**
	 * The ids of the stored rows of a table.
	 *
	 * @param int $tableId the table
	 * @return list<int> the ids
	 */
	public function rowIds(int $tableId): array {
		return array_keys($this->rows[$tableId] ?? []);
	}

	/**
	 * The calls of a double's method.
	 *
	 * @param string $label double label
	 * @param string $method method name
	 * @return list<list<mixed>> the arguments of each call
	 */
	public function callsOf(string $label, string $method): array {
		$calls = [];
		foreach ($this->journal as [$l, $m, $arguments]) {
			if ($l === $label && $m === $method) {
				$calls[] = $arguments;
			}
		}
		return $calls;
	}

	/**
	 * Tables is enabled.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool always true
	 */
	public function isEnabledFor(string $userId): bool {
		return true;
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
	 * The doubles of the Tables services a row write uses.
	 *
	 * @param string $class fully qualified Tables class name
	 * @param string $userId authenticated user UID
	 * @return object the double
	 */
	protected function resolve(string $class, string $userId): object {
		return match ($class) {
			'OCA\\Tables\\Service\\PermissionsService' => $this->permissions(),
			'OCA\\Tables\\Db\\ViewMapper' => new TablesDouble('ViewMapper', ['find' => fn (int $id): TablesDouble => $this->view($id)], $this->journal),
			'OCA\\Tables\\Db\\TableMapper' => new TablesDouble('TableMapper', ['find' => fn (int $id): TablesDouble => $this->table($id)], $this->journal),
			'OCA\\Tables\\Db\\ColumnMapper' => new TablesDouble('ColumnMapper', [
				'find' => fn (int $id): TablesDouble => isset($this->columns[$id]) ? $this->column($this->columns[$id]) : throw new DoesNotExistException('no column'),
				'findAll' => fn (array $ids): array => array_map(fn (int $id): TablesDouble => $this->column($this->columns[$id]), array_values(array_filter($ids, fn (int $id): bool => isset($this->columns[$id])))),
				'findAllByTable' => fn (int $tableId): array => array_map($this->column(...), array_values(array_filter($this->columns, static fn (array $c): bool => (int)$c['tableId'] === $tableId))),
			], $this->journal),
			'OCA\\Tables\\Db\\Row2Mapper' => new TablesDouble('Row2Mapper', ['findAll' => $this->findRows(...)], $this->journal),
			'OCA\\Tables\\Helper\\ColumnsHelper' => new TablesDouble('ColumnsHelper', ['resolveSearchValue' => fn (string $p, string $u): string|array => ($this->resolveSearch)($p, $u)], $this->journal),
			'OCA\\Tables\\Service\\RowService' => new TablesDouble('RowService', ['create' => $this->create(...), 'updateSet' => $this->updateSet(...)], $this->journal),
			default => throw new \LogicException($class . ' must not be resolved by the row writes'),
		};
	}

	/**
	 * Whether a user holds a permission on a node: the owner of the table holds them all, on its views too.
	 *
	 * @param string $userId the user
	 * @param string $type 'table' or 'view'
	 * @param int $id the node
	 * @param string $permission read, create or update
	 * @return bool true when held
	 */
	private function allows(string $userId, string $type, int $id, string $permission): bool {
		$tableId = $type === 'view' ? ($this->views[$id]['tableId'] ?? 0) : $id;
		if ($type === 'view' && !isset($this->views[$id]) || $type === 'table' && !isset($this->tables[$id])) {
			return false;
		}
		return ($this->tables[$tableId]['owner'] ?? null) === $userId || isset($this->grants[$userId][$type . ':' . $id][$permission]);
	}

	/**
	 * PermissionsService with the native write and read checks.
	 *
	 * @return TablesDouble the double
	 */
	private function permissions(): TablesDouble {
		return new TablesDouble('PermissionsService', [
			'canCreateRows' => fn (object $element, string $type = 'view', ?string $userId = null): bool => $this->allows((string)$userId, $type, (int)$element->getId(), 'create'),
			'canReadRowsByElementId' => fn (int $id, string $type, ?string $userId = null): bool => $this->allows((string)$userId, $type, $id, 'read'),
			'canUpdateRowsByViewId' => fn (int $id, ?string $userId = null): bool => $this->allows((string)$userId, 'view', $id, 'update'),
			'canUpdateRowsByTableId' => fn (int $id, ?string $userId = null): bool => $this->allows((string)$userId, 'table', $id, 'update'),
		], $this->journal);
	}

	/**
	 * A view double, or DoesNotExistException.
	 *
	 * @param int $id the view
	 * @return TablesDouble the double
	 */
	private function view(int $id): TablesDouble {
		$view = $this->views[$id] ?? throw new DoesNotExistException('no view');
		$filterArray = $view['filterArray'] ?? ($view['filter'] === null ? [] : (array)json_decode($view['filter'], true));
		return new TablesDouble("View#$id", [
			'getId' => static fn (): int => $id,
			'getTableId' => static fn (): int => $view['tableId'],
			'getColumnIds' => static fn (): array => $view['columns'],
			'getColumnsSettingsArray' => fn (): array => array_map(fn (int $columnId, int $order): TablesDouble => new TablesDouble('ViewColumnInformation', [
				'jsonSerialize' => static fn (): array => ['columnId' => $columnId, 'order' => $order + 1] + ($view['settings'][$columnId] ?? ['readonly' => false, 'mandatory' => false]),
			], $this->journal), $view['columns'], array_keys($view['columns'])),
			'getFilter' => static fn (): ?string => $view['filter'],
			'getSort' => static fn (): ?string => $view['sort'],
			'getFilterArray' => static fn (): array => $filterArray,
			'getSortArray' => static fn (): array => $view['sort'] === null ? [] : (array)json_decode($view['sort'], true),
			'isFederated' => static fn (): bool => $view['federated'],
		], $this->journal);
	}

	/**
	 * A table double, or DoesNotExistException.
	 *
	 * @param int $id the table
	 * @return TablesDouble the double
	 */
	private function table(int $id): TablesDouble {
		$table = $this->tables[$id] ?? throw new DoesNotExistException('no table');
		return new TablesDouble("Table#$id", [
			'getId' => static fn (): int => $id,
			'getOwnership' => static fn (): string => $table['owner'],
			'isFederated' => static fn (): bool => $table['federated'],
		], $this->journal);
	}

	/**
	 * A column double.
	 *
	 * @param array<string, mixed> $fields the column
	 * @return TablesDouble the double
	 */
	private function column(array $fields): TablesDouble {
		return new TablesDouble('Column#' . $fields['id'], [
			'getTableId' => static fn (): int => (int)$fields['tableId'],
			'jsonSerialize' => static fn (): array => $fields,
		], $this->journal);
	}

	/**
	 * Row2Mapper::findAll() over the stored rows, with the conditions the row writes send (id and equality, contains).
	 *
	 * @param list<int> $columnIds the columns
	 * @param int $tableId the table
	 * @param int|null $limit most rows
	 * @param int|null $offset rows skipped
	 * @param list<list<array{columnId:int, operator:string, value:string}>>|null $filter the groups
	 * @param array<mixed>|null $sort the sort
	 * @param string|null $userId the user
	 * @return list<TablesDouble> the rows
	 */
	private function findRows(array $columnIds, int $tableId, ?int $limit = null, ?int $offset = null, ?array $filter = null, ?array $sort = null, ?string $userId = null): array {
		$found = [];
		foreach ($this->rows[$tableId] ?? [] as $id => $row) {
			if ($filter === null || self::matches($id, $row['data'], $filter)) {
				$found[] = $this->rowDouble($tableId, $id, $row, $columnIds);
			}
		}
		return array_slice($found, (int)$offset, $limit);
	}

	/**
	 * Whether a row matches a disjunction of conjunctions.
	 *
	 * @param int $id row id
	 * @param array<int, mixed> $data its values
	 * @param list<list<array{columnId:int, operator:string, value:string}>> $filter the groups
	 * @return bool true when a group holds
	 */
	private static function matches(int $id, array $data, array $filter): bool {
		foreach ($filter as $group) {
			$all = true;
			foreach ($group as $condition) {
				$value = $condition['columnId'] === -1 ? (string)$id : (is_scalar($data[$condition['columnId']] ?? null) ? (string)$data[$condition['columnId']] : '');
				$all = $all && match ($condition['operator']) {
					'is-equal' => $value === (string)$condition['value'],
					'contains' => str_contains($value, (string)$condition['value']),
					default => false,
				};
			}
			if ($all) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A row double holding the given columns.
	 *
	 * @param int $tableId its table
	 * @param int $id row id
	 * @param array{data:array<int, mixed>, lastEditAt:string} $row the stored row
	 * @param list<int> $columnIds the columns
	 * @return TablesDouble the double
	 */
	private function rowDouble(int $tableId, int $id, array $row, array $columnIds): TablesDouble {
		$data = [];
		foreach ($row['data'] as $columnId => $value) {
			if (in_array($columnId, $columnIds, true)) {
				$data[] = ['columnId' => $columnId, 'value' => $value];
			}
		}
		$serialized = ['id' => $id, 'tableId' => $tableId, 'createdBy' => 'alice', 'lastEditBy' => 'alice', 'lastEditAt' => $row['lastEditAt'], 'data' => $data];
		return new TablesDouble('Row#' . $id, ['jsonSerialize' => static fn (): array => $serialized], $this->journal);
	}

	/**
	 * RowService::create(): parse, add the view's hidden values, write, then fire the event.
	 *
	 * @param int|null $tableId the table
	 * @param int|null $viewId the view
	 * @param list<array{columnId:int, value:mixed}> $data the cells
	 * @return TablesDouble the new row, with the view's columns through a view
	 * @throws BadRequestError when the sink refuses
	 * @throws \RuntimeException when a listener fails after the write
	 */
	private function create(?int $tableId, ?int $viewId, array $data): TablesDouble {
		if ($this->sink === 'refuse') {
			throw new BadRequestError('refused');
		}
		$tableId ??= $this->views[(int)$viewId]['tableId'];
		$values = [];
		foreach ($data as $cell) {
			$values[(int)$cell['columnId']] = $this->parse($this->columns[(int)$cell['columnId']], $cell['value']);
		}
		if ($viewId !== null) {
			$view = $this->view($viewId);
			foreach ((array)$view->getFilterArray() as $group) {
				foreach ((array)$group as $rule) {
					if (!isset($rule['columnId'], $rule['operator'], $rule['value']) || in_array($rule['columnId'], $view->getColumnIds()) || $rule['columnId'] < 0
						|| !in_array($rule['operator'], ['is-equal', 'is-not-equal']) || array_key_exists((int)$rule['columnId'], $values)) {
						continue;
					}
					$values[(int)$rule['columnId']] = ($this->resolveSearch)((string)$rule['value'], $this->actor);
				}
			}
		}
		$id = 1000 + count($this->rows[$tableId] ?? []);
		$stored = [];
		foreach ($values as $columnId => $value) {
			$stored[$columnId] = $this->store($this->columns[$columnId], $value);
		}
		$this->withRow($tableId, $id, $stored, '2026-10-26 12:00:00');
		if ($this->sink === 'failAfter') {
			throw new \RuntimeException('listener failed');
		}
		$columns = $viewId !== null ? $this->views[$viewId]['columns'] : array_keys(array_filter($this->columns, static fn (array $c): bool => (int)$c['tableId'] === $tableId));
		return $this->rowDouble($tableId, $id, ['data' => $values, 'lastEditAt' => '2026-10-26 12:00:00'], $columns);
	}

	/**
	 * RowService::updateSet(): parse, write the cells, then fire the event.
	 *
	 * @param int $id the row
	 * @param int|null $viewId the view
	 * @param list<array{columnId:int, value:mixed}> $data the cells
	 * @param string $userId the user
	 * @param int|null $tableId the table
	 * @return TablesDouble the row, with the view's columns through a view
	 * @throws BadRequestError when the sink refuses
	 * @throws \RuntimeException when a listener fails after the write
	 */
	private function updateSet(int $id, ?int $viewId, array $data, string $userId, ?int $tableId): TablesDouble {
		if ($this->sink === 'refuse') {
			throw new BadRequestError('refused');
		}
		$tableId = $viewId !== null ? $this->views[$viewId]['tableId'] : (int)$tableId;
		$memory = $this->rows[$tableId][$id];
		foreach ($data as $cell) {
			$column = $this->columns[(int)$cell['columnId']];
			$memory['data'][(int)$cell['columnId']] = $this->parse($column, $cell['value']);
			$this->rows[$tableId][$id]['data'][(int)$cell['columnId']] = $this->store($column, $memory['data'][(int)$cell['columnId']]);
		}
		$this->rows[$tableId][$id]['lastEditAt'] = $memory['lastEditAt'] = '2026-10-26 12:00:00';
		if ($this->sink === 'failAfter') {
			throw new \RuntimeException('listener failed');
		}
		$columns = $viewId !== null ? $this->views[$viewId]['columns'] : array_keys(array_filter($this->columns, static fn (array $c): bool => (int)$c['tableId'] === $tableId));
		return $this->rowDouble($tableId, $id, $memory, $columns);
	}

	/**
	 * What the cell mapper stores of a parsed value (tables-write.json, mappers): a missing number becomes 0 before 2.3.2.
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the parsed value
	 * @return mixed the stored value
	 */
	private function store(array $column, mixed $value): mixed {
		if ($column['type'] === 'number' && !is_numeric($value)) {
			return version_compare($this->tablesVersion, '2.3.2', '>=') ? null : 0.0;
		}
		return $value;
	}

	/**
	 * What the pinned parser of each type makes of a value it accepts (tables-write.json).
	 *
	 * @param array<string, mixed> $column the column
	 * @param mixed $value the value
	 * @return mixed the parsed value
	 */
	private function parse(array $column, mixed $value): mixed {
		return match ([$column['type'], $column['subtype'] ?? '']) {
			['selection', 'check'] => in_array($value, ['yes', '1', true, 1, 'true', 'TRUE'], true) ? 'true' : 'false',
			['number', 'stars'], ['number', 'progress'] => (int)$value,
			['number', ''] => $value === null ? null : (float)$value,
			['selection', ''] => $value === null ? null : (in_array($this->tablesVersion, ['1.0.10', '2.2.2', '2.3.2'], true) ? (string)$value : (int)$value),
			['selection', 'multi'] => $value === null ? [] : (static function (array $ids): array {
				sort($ids, SORT_NUMERIC);
				return $ids;
			})($value),
			['usergroup', ''] => $value ?? [],
			default => $value,
		};
	}
}
