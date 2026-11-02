<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Support;

use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../Stubs/tables_stubs.php';

/**
 * A Tables app in memory, behind the same TablesServices the module uses, with the access rules of Tables 1.0.x and 2.x:
 * - a table is readable by its owner and by whoever it is shared with, and managed by its owner and by a share with
 *   manage;
 * - a view is open to whoever manages its table, whoever it is shared with and the members of an application (context)
 *   that holds it;
 * - TableService::findAll() with `createTutorial = true` creates a tutorial table for a user without any, a write the
 *   fake records in {@see self::$writes}.
 * Every call into a service is journaled with its arguments, and the services answer only the methods the module may use.
 */
class FakeTablesApp extends TablesServices {
	/** @var \ArrayObject<int, array{0:string, 1:string, 2:list<mixed>}> every call into the fake app, in order */
	public \ArrayObject $journal;

	/** Whether the Tables app counts as enabled for the user. */
	public bool $enabled = true;

	/** @var list<string> writes the fake app made, such as "tutorial table for carol" */
	public array $writes = [];

	/** @var array<int, array{id:int, title:string, owner:string, emoji:string, description:string, archived:bool}> tables by id */
	private array $tables = [];

	/** @var array<string, array<int, bool>> tables shared with a user, by user and table id: true with manage */
	private array $tableShares = [];

	/** @var array<string, list<int>> tables TableService::findAll() returns although the permissions refuse them, by user */
	private array $staleTables = [];

	/** @var array<int, array{id:int, tableId:int, title:string, columns:list<int>, settings:array<int, array{readonly:bool, mandatory:bool}>}> views by id */
	private array $views = [];

	/** @var array<string, list<int>> views shared with a user, by user */
	private array $viewShares = [];

	/** @var array<string, list<int>> views findSharedViewsWithMe() returns although canAccessView() refuses them, by user */
	private array $staleViews = [];

	/** @var array<string, list<list<array{node_id:int, node_type:int, permissions:int}>>> nodes of the contexts of a user */
	private array $contexts = [];

	/** @var array<int, array<string, mixed>> columns by id, as Column::jsonSerialize() returns them without the view part */
	private array $columns = [];

	/**
	 * Builds an empty Tables app; the collaborators of the parent are stubs, since every method that used them is replaced.
	 *
	 * @return void
	 */
	public function __construct() {
		$stub = \Closure::bind(static fn (string $type): object => TestCase::createStub($type), null, TestCase::class);
		parent::__construct($stub(IAppManager::class), $stub(ContainerInterface::class), $stub(IUserManager::class));
		$this->journal = new \ArrayObject();
	}

	/**
	 * Stores a table.
	 *
	 * @param int $id table id
	 * @param string $owner UID of the owner
	 * @param string $title title
	 * @param array{emoji?:string, description?:string, archived?:bool} $fields other fields
	 * @return self this app
	 */
	public function withTable(int $id, string $owner, string $title, array $fields = []): self {
		$this->tables[$id] = ['id' => $id, 'title' => $title, 'owner' => $owner] + $fields + ['emoji' => '', 'description' => '', 'archived' => false];
		return $this;
	}

	/**
	 * Shares a table with a user.
	 *
	 * @param int $tableId table id
	 * @param string $userId UID
	 * @param bool $manage whether the share gives manage
	 * @return self this app
	 */
	public function sharingTable(int $tableId, string $userId, bool $manage = false): self {
		$this->tableShares[$userId][$tableId] = $manage;
		return $this;
	}

	/**
	 * Makes TableService::findAll() return a table its permission checks refuse, as a share revoked in between would.
	 *
	 * @param int $tableId table id
	 * @param string $userId UID
	 * @return self this app
	 */
	public function withStaleTable(int $tableId, string $userId): self {
		$this->staleTables[$userId][] = $tableId;
		return $this;
	}

	/**
	 * Stores a view.
	 *
	 * @param int $id view id
	 * @param int $tableId its table
	 * @param string $title title
	 * @param list<int> $columns ids of the columns it shows, in its order
	 * @param array<int, array{readonly:bool, mandatory:bool}> $settings read-only and mandatory settings by column id
	 * @return self this app
	 */
	public function withView(int $id, int $tableId, string $title, array $columns, array $settings = []): self {
		$this->views[$id] = ['id' => $id, 'tableId' => $tableId, 'title' => $title, 'columns' => $columns, 'settings' => $settings];
		return $this;
	}

	/**
	 * Shares a view with a user.
	 *
	 * @param int $viewId view id
	 * @param string $userId UID
	 * @return self this app
	 */
	public function sharingView(int $viewId, string $userId): self {
		$this->viewShares[$userId][] = $viewId;
		return $this;
	}

	/**
	 * Makes findSharedViewsWithMe() return a view canAccessView() refuses.
	 *
	 * @param int $viewId view id
	 * @param string $userId UID
	 * @return self this app
	 */
	public function withStaleView(int $viewId, string $userId): self {
		$this->staleViews[$userId][] = $viewId;
		return $this;
	}

	/**
	 * Gives a user an application (context) with these nodes.
	 *
	 * @param string $userId UID of a member
	 * @param list<array{node_id:int, node_type:int, permissions:int}> $nodes nodes of the context
	 * @return self this app
	 */
	public function withContext(string $userId, array $nodes): self {
		$this->contexts[$userId][] = $nodes;
		return $this;
	}

	/**
	 * Stores a column.
	 *
	 * @param array<string, mixed> $fields fields as Column::jsonSerialize() returns them; id and tableId required
	 * @return self this app
	 */
	public function withColumn(array $fields): self {
		$this->columns[(int)$fields['id']] = $fields;
		return $this;
	}

	/**
	 * Enablement as the test set it.
	 *
	 * @param string $userId authenticated user UID
	 * @return bool whether Tables counts as enabled
	 */
	public function isEnabledFor(string $userId): bool {
		return $this->enabled;
	}

	/**
	 * The calls of a service method, with their arguments.
	 *
	 * @param string $label service label
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
	 * The labels of every object the module read, such as "View#7".
	 *
	 * @return list<string> labels, once each
	 */
	public function readObjects(): array {
		return array_values(array_unique(array_map(static fn (array $call): string => $call[0], $this->journal->getArrayCopy())));
	}

	/**
	 * Resolves the Tables services to strict doubles; the real resolution is covered by TablesServicesTest.
	 *
	 * @param string $class fully qualified Tables class name
	 * @param string $userId authenticated user UID
	 * @return object the double
	 * @throws TablesUnavailableException when the test turned Tables off
	 */
	protected function resolve(string $class, string $userId): object {
		if (!$this->enabled) {
			throw new TablesUnavailableException();
		}
		return match ($class) {
			'OCA\\Tables\\Service\\TableService' => $this->fakeTableService(),
			'OCA\\Tables\\Service\\ViewService' => $this->fakeViewService(),
			'OCA\\Tables\\Service\\ColumnService' => $this->fakeColumnService(),
			'OCA\\Tables\\Service\\PermissionsService' => $this->fakePermissionsService(),
			'OCA\\Tables\\Service\\ContextService' => $this->fakeContextService(),
		};
	}

	/**
	 * TableService, answering only findAll().
	 *
	 * @return TablesDouble the double
	 */
	private function fakeTableService(): TablesDouble {
		return new TablesDouble('TableService', [
			'findAll' => function (?string $userId = null, bool $skipEnhancement = false, bool $skipShared = false, bool $createTutorial = true): array {
				$ids = array_keys(array_filter($this->tables, fn (array $t): bool => $this->access($t['id'], (string)$userId) !== null));
				if ($ids === [] && $createTutorial) {
					$this->writes[] = 'tutorial table for ' . $userId;
				}
				$ids = array_unique([...$ids, ...($this->staleTables[(string)$userId] ?? [])]);
				return array_map(fn (int $id): TablesDouble => $this->table($id), array_reverse($ids));
			},
		], $this->journal);
	}

	/**
	 * ViewService, answering only findAll(), find() and findSharedViewsWithMe().
	 *
	 * @return TablesDouble the double
	 */
	private function fakeViewService(): TablesDouble {
		return new TablesDouble('ViewService', [
			'findAll' => function (object $table, ?string $userId = null): array {
				if ($this->access((int)$table->getId(), (string)$userId) !== true) {
					throw new PermissionError('PermissionError: can not read views for tableId ' . $table->getId());
				}
				return array_map(fn (array $v): TablesDouble => $this->view($v['id']), array_values(array_filter($this->views, static fn (array $v): bool => $v['tableId'] === (int)$table->getId())));
			},
			'find' => function (int $id, bool $skipEnhancement = false, ?string $userId = null): TablesDouble {
				if (!isset($this->views[$id])) {
					throw new NotFoundError("View $id not found");
				}
				if (!$this->opensView($id, (string)$userId)) {
					throw new PermissionError("PermissionError: can not read view with id $id for $userId");
				}
				return $this->view($id);
			},
			'findSharedViewsWithMe' => fn (?string $userId = null): array => array_map($this->view(...), [...($this->viewShares[(string)$userId] ?? []), ...($this->staleViews[(string)$userId] ?? [])]),
		], $this->journal);
	}

	/**
	 * ColumnService, answering only findAllByTable() and findAllByView().
	 *
	 * @return TablesDouble the double
	 */
	private function fakeColumnService(): TablesDouble {
		return new TablesDouble('ColumnService', [
			'findAllByTable' => function (int $tableId, ?string $userId = null): array {
				if ($this->access($tableId, (string)$userId) === null) {
					throw new PermissionError("PermissionError: can not read columns for tableId $tableId");
				}
				return array_map(fn (array $c): TablesDouble => $this->column($c, null), array_values(array_filter($this->columns, static fn (array $c): bool => (int)$c['tableId'] === $tableId)));
			},
			'findAllByView' => function (int $viewId, ?string $userId = null): array {
				if (!isset($this->views[$viewId]) || !$this->opensView($viewId, (string)$userId)) {
					throw new PermissionError("PermissionError: can not read columns for viewId $viewId");
				}
				$view = $this->views[$viewId];
				return array_map(fn (int $id): TablesDouble => $this->column($this->columns[$id], $view['settings'][$id] ?? ['readonly' => false, 'mandatory' => false]), $view['columns']);
			},
		], $this->journal);
	}

	/**
	 * PermissionsService, answering only the read checks.
	 *
	 * @return TablesDouble the double
	 */
	private function fakePermissionsService(): TablesDouble {
		return new TablesDouble('PermissionsService', [
			'canReadColumnsByTableId' => fn (int $tableId, ?string $userId = null): bool => $this->access($tableId, (string)$userId) !== null,
			'canManageTable' => fn (object $table, ?string $userId = null): bool => $this->access((int)$table->getId(), (string)$userId) === true,
			'canAccessView' => fn (object $view, ?string $userId = null): bool => $this->opensView((int)$view->getId(), (string)$userId),
		], $this->journal);
	}

	/**
	 * ContextService, answering only findAll().
	 *
	 * @return TablesDouble the double
	 */
	private function fakeContextService(): TablesDouble {
		return new TablesDouble('ContextService', [
			'findAll' => fn (?string $userId): array => array_map(fn (array $nodes): TablesDouble => new TablesDouble('Context', [
				'getNodes' => static fn (): array => $nodes,
			], $this->journal), $this->contexts[(string)$userId] ?? []),
		], $this->journal);
	}

	/**
	 * The access of a user to a table.
	 *
	 * @param int $tableId table id
	 * @param string $userId UID
	 * @return bool|null true to manage, false to read, null for none
	 */
	private function access(int $tableId, string $userId): ?bool {
		if (($this->tables[$tableId]['owner'] ?? null) === $userId) {
			return true;
		}
		return $this->tableShares[$userId][$tableId] ?? null;
	}

	/**
	 * Whether a user may open a view: manage of its table, a share of it, or a context holding it.
	 *
	 * @param int $viewId view id
	 * @param string $userId UID
	 * @return bool whether the user may open it
	 */
	private function opensView(int $viewId, string $userId): bool {
		if (!isset($this->views[$viewId])) {
			return false;
		}
		if ($this->access($this->views[$viewId]['tableId'], $userId) === true || in_array($viewId, $this->viewShares[$userId] ?? [], true)) {
			return true;
		}
		foreach ($this->contexts[$userId] ?? [] as $nodes) {
			foreach ($nodes as $node) {
				if ($node['node_type'] === 1 && $node['node_id'] === $viewId) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A strict double of OCA\Tables\Db\Table.
	 *
	 * @param int $id table id
	 * @return TablesDouble the double
	 */
	private function table(int $id): TablesDouble {
		$row = $this->tables[$id];
		return new TablesDouble("Table#$id", [
			'getId' => static fn (): int => $row['id'],
			'getTitle' => static fn (): string => $row['title'],
			'getEmoji' => static fn (): ?string => $row['emoji'],
			'getDescription' => static fn (): ?string => $row['description'],
			'getArchived' => static fn (): bool => $row['archived'],
			'getOwnership' => static fn (): string => $row['owner'],
		], $this->journal);
	}

	/**
	 * A strict double of OCA\Tables\Db\View.
	 *
	 * @param int $id view id
	 * @return TablesDouble the double
	 */
	private function view(int $id): TablesDouble {
		$row = $this->views[$id];
		return new TablesDouble("View#$id", [
			'getId' => static fn (): int => $row['id'],
			'getTableId' => static fn (): int => $row['tableId'],
			'getTitle' => static fn (): string => $row['title'],
			'getEmoji' => static fn (): ?string => null,
			'getDescription' => static fn (): ?string => null,
			'getColumnIds' => static fn (): array => $row['columns'],
		], $this->journal);
	}

	/**
	 * A strict double of OCA\Tables\Db\Column, serialized as the Tables app does.
	 *
	 * @param array<string, mixed> $fields stored fields
	 * @param array{readonly:bool, mandatory:bool}|null $settings the view's settings, or null outside a view
	 * @return TablesDouble the double
	 */
	private function column(array $fields, ?array $settings): TablesDouble {
		$data = $fields + ['viewColumnInformation' => $settings];
		return new TablesDouble('Column#' . $fields['id'], [
			'jsonSerialize' => static fn (): array => $data,
		], $this->journal);
	}
}
