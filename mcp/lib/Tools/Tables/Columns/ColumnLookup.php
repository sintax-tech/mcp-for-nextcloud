<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Columns;

use OCA\Mcp\Tools\Tables\TablesCalls;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\Tables\TablesServices;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The columns of a table or of a view, authorized before anything of them is read:
 * - a table: PermissionsService::canReadColumnsByTableId() for the user, then ColumnService::findAllByTable();
 * - a view: ViewService::find() with the user (which checks canAccessView()), canAccessView() again, then
 *   ColumnService::findAllByView(), which returns only the columns the view shows, with the view's read-only and
 *   mandatory settings attached.
 * A table or view the user cannot open reads exactly like a missing one.
 */
class ColumnLookup {
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
	 * The columns of a table.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $tableId table id
	 * @return list<object> OCA\Tables\Db\Column objects
	 * @throws ToolFailure when the table is not the user's to open, or the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function forTable(string $userId, int $tableId): array {
		$permissions = $this->services->permissionsService($userId);
		$columns = $this->services->columnService($userId);
		if (!TablesCalls::run(static fn (): bool => (bool)$permissions->canReadColumnsByTableId($tableId, $userId))) {
			throw new ToolFailure(TablesMessages::notFound());
		}
		return TablesCalls::run(static fn (): array => array_values($columns->findAllByTable($tableId, $userId)));
	}

	/**
	 * The columns of a view.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $viewId view id
	 * @return list<object> OCA\Tables\Db\Column objects, with the view's settings
	 * @throws ToolFailure when the view is not the user's to open, or the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function forView(string $userId, int $viewId): array {
		$views = $this->services->viewService($userId);
		$permissions = $this->services->permissionsService($userId);
		$columns = $this->services->columnService($userId);
		$view = TablesCalls::run(static fn (): object => $views->find($viewId, true, $userId));
		if (!TablesCalls::run(static fn (): bool => (bool)$permissions->canAccessView($view, $userId))) {
			throw new ToolFailure(TablesMessages::notFound());
		}
		return TablesCalls::run(static fn (): array => array_values($columns->findAllByView($viewId, $userId)));
	}
}
