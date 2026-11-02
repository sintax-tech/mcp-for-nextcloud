<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Reading;

use OCA\Mcp\Modules\Tables\Integration\TablesCalls;
use OCA\Mcp\Modules\Tables\Integration\TablesServices;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The tables a user can open, as the Tables app finds them, each authorized again before it is shown.
 *
 * TableService::findAll() returns the user's own tables, the ones shared with them and the ones of their applications
 * (contexts). It is called with `createTutorial = false`: its default creates a tutorial table for a user without any,
 * a write no listing may do. Its enrichment is skipped (`skipTableEnhancement = true`): it lists views through
 * ViewService::findAll() without the user, so with whoever the session holds. Each table is then checked with
 * PermissionsService::canReadColumnsByTableId() for the user (owner, share, group, team or context), and whether the user
 * manages it (canManageTable()) tells whether its views can be listed, as the Tables app lists them.
 */
class TablesDiscovery {
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
	 * The tables the user can open, in id order.
	 *
	 * @param string $userId authenticated user UID
	 * @return list<array{table:object, manages:bool}> each table and whether the user manages it
	 * @throws ToolFailure when the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function tables(string $userId): array {
		$tableService = $this->services->tableService($userId);
		$permissions = $this->services->permissionsService($userId);
		$tables = TablesCalls::run(static fn (): array => array_values($tableService->findAll($userId, true, false, false)));
		$found = [];
		foreach ($tables as $table) {
			$id = (int)$table->getId();
			if (!TablesCalls::run(static fn (): bool => (bool)$permissions->canReadColumnsByTableId($id, $userId))) {
				continue;
			}
			$found[$id] = ['table' => $table, 'manages' => TablesCalls::run(static fn (): bool => (bool)$permissions->canManageTable($table, $userId))];
		}
		ksort($found);
		return array_values($found);
	}
}
