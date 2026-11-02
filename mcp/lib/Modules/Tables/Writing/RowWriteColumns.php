<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Reading\RowsRefusal;
use OCA\Mcp\Modules\Tables\Integration\TablesCalls;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The target of a write, read the way RowService::create()/updateSet() read it, under the native write permission and
 * never through a read of rows:
 * - creating through a view: ViewMapper::find(), PermissionsService::canCreateRows($view, 'view'), the view's columns
 *   (ColumnMapper::findAll($view->getColumnIds())) and its settings (View::getColumnsSettingsArray());
 * - creating in a table: TableMapper::find(), canCreateRows($table, 'table'), ColumnMapper::findAllByTable();
 * - updating: canReadRowsByElementId() and canUpdateRowsByViewId()/canUpdateRowsByTableId() of the target, as updateSet()
 *   asks, then the same metadata.
 * Without the permission, and for a target that does not exist, the answer is the same "not found". A share that only
 * allows creating rows therefore gets its columns here, which the read lookup of TB-1 would refuse. A federated target
 * (Tables 2.3 sends those to its FederationService) and a view listing a column of another table are refused. Nothing
 * falls back from a view to its table.
 */
class RowWriteColumns {
	/** First pinned Tables release with federated tables and views. */
	public const FEDERATION_SINCE = '2.3.0';

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
	 * The target of a write.
	 *
	 * @param RowWriteRequest $request the write
	 * @param string $userId authenticated user UID
	 * @return RowWriteTarget what the write goes through
	 * @throws ToolFailure when the target is not the user's to write, federated or inconsistent
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function target(RowWriteRequest $request, string $userId): RowWriteTarget {
		$version = $this->services->version();
		$permissions = $this->services->permissionsService($userId);
		$columnMapper = $this->services->columnMapper($userId);
		$update = $request->rowId !== null;
		$id = $request->targetId;
		$view = null;
		$settings = [];
		if ($request->targetType === 'view') {
			$views = $this->services->viewMapper($userId);
			$view = self::found(static fn (): object => $views->find($id));
			$allowed = $update
				? TablesCalls::run(static fn (): bool => (bool)$permissions->canReadRowsByElementId($id, 'view', $userId)
					&& (bool)$permissions->canUpdateRowsByViewId($id, $userId))
				: TablesCalls::run(static fn (): bool => (bool)$permissions->canCreateRows($view, 'view', $userId));
			if (!$allowed) {
				throw new ToolFailure(TablesMessages::notFound());
			}
			if (self::federated($view, $version)) {
				throw RowWriteRefusal::federated();
			}
			$tableId = (int)$view->getTableId();
			$columnIds = array_map('intval', (array)$view->getColumnIds());
			$columns = TablesCalls::run(static fn (): array => array_values((array)$columnMapper->findAll($columnIds)));
			foreach ((array)$view->getColumnsSettingsArray() as $information) {
				$data = (array)$information->jsonSerialize();
				$settings[(int)($data['columnId'] ?? 0)] = ['readonly' => (bool)($data['readonly'] ?? false), 'mandatory' => (bool)($data['mandatory'] ?? false)];
			}
		} else {
			$tableId = $id;
			$allowed = $update
				? TablesCalls::run(static fn (): bool => (bool)$permissions->canReadRowsByElementId($id, 'table', $userId)
					&& (bool)$permissions->canUpdateRowsByTableId($id, $userId))
				: null;
			if ($allowed === false) {
				throw new ToolFailure(TablesMessages::notFound());
			}
		}
		$tables = $this->services->tableMapper($userId);
		$table = self::found(static fn (): object => $tables->find($tableId));
		if ($view === null) {
			if (!$update && !TablesCalls::run(static fn (): bool => (bool)$permissions->canCreateRows($table, 'table', $userId))) {
				throw new ToolFailure(TablesMessages::notFound());
			}
			if (self::federated($table, $version)) {
				throw RowWriteRefusal::federated();
			}
			$columns = TablesCalls::run(static fn (): array => array_values((array)$columnMapper->findAllByTable($tableId)));
		}
		return new RowWriteTarget($request->targetType, $id, $tableId, $view, self::byId($columns, $tableId), $settings,
			(string)$table->getOwnership() === $userId, $version);
	}

	/**
	 * An entity the Tables app finds, or "not found".
	 *
	 * @param callable(): object $find the lookup
	 * @return object the entity
	 * @throws ToolFailure when it is not there or Tables failed
	 */
	private static function found(callable $find): object {
		return TablesCalls::run($find);
	}

	/**
	 * Whether a table or view is federated. Releases before 2.3 have no federation and no isFederated(): there the node
	 * found by TableMapper::find() or ViewMapper::find() is the local one, and nothing is asked. The one place that asks,
	 * for the relation gate too.
	 *
	 * @param object $node OCA\Tables\Db\Table or View
	 * @param string $version installed Tables version
	 * @return bool true for a federated node
	 */
	public static function federated(object $node, string $version): bool {
		return version_compare($version, self::FEDERATION_SINCE, '>=') && (bool)$node->isFederated();
	}

	/**
	 * The columns by id, each of the table.
	 *
	 * @param list<object> $columns OCA\Tables\Db\Column objects
	 * @param int $tableId the table
	 * @return array<int, array<string, mixed>> the serialized columns by id, in order
	 * @throws RowsRefusal when a column belongs to another table
	 */
	private static function byId(array $columns, int $tableId): array {
		$byId = [];
		foreach ($columns as $column) {
			$data = (array)$column->jsonSerialize();
			if ((int)($data['tableId'] ?? 0) !== $tableId) {
				throw RowsRefusal::savedRulesUnusable();
			}
			$byId[(int)$data['id']] = $data;
		}
		return $byId;
	}
}
