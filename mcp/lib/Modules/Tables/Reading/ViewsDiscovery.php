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
 * The views a user can open, from the three places the Tables app has them, each authorized again before it is shown:
 * - the views of the tables the user manages (ViewService::findAll() with the user, which itself requires manage);
 * - the views shared with the user (ViewService::findSharedViewsWithMe());
 * - the views of the user's applications (ContextService::findAll(), view nodes, each read with ViewService::find()).
 * Every view is checked with PermissionsService::canAccessView() for the user before it is kept. The views of a table
 * shared without manage are not listed, as the Tables app does not list them; the table answer says so per table
 * (`viewsListed`).
 */
class ViewsDiscovery {
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
	 * The views the user can open, in table then view id order.
	 *
	 * @param string $userId authenticated user UID
	 * @param list<array{table:object, manages:bool}> $tables the tables returned by TablesDiscovery::tables()
	 * @return list<array{view:object, via:list<string>}> each view and where it was found: table, shared, context
	 * @throws ToolFailure when the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function views(string $userId, array $tables): array {
		$viewService = $this->services->viewService($userId);
		$permissions = $this->services->permissionsService($userId);
		$candidates = [];
		foreach ($tables as $entry) {
			if (!$entry['manages']) {
				continue;
			}
			$table = $entry['table'];
			foreach (TablesCalls::run(static fn (): array => array_values($viewService->findAll($table, $userId))) as $view) {
				$candidates[] = [$view, 'table'];
			}
		}
		foreach (TablesCalls::run(static fn (): array => array_values($viewService->findSharedViewsWithMe($userId))) as $view) {
			$candidates[] = [$view, 'shared'];
		}
		foreach ($this->contextViewIds($userId) as $viewId) {
			try {
				$candidates[] = [TablesCalls::run(static fn (): object => $viewService->find($viewId, true, $userId)), 'context'];
			} catch (ToolFailure $e) {
				// A context may name a view the user cannot open (anymore): it is not theirs, so it is left out.
				if (!TablesCalls::isNotFound($e->getPrevious() ?? $e)) {
					throw $e;
				}
			}
		}
		$found = [];
		foreach ($candidates as [$view, $via]) {
			$id = (int)$view->getId();
			if (!isset($found[$id])) {
				if (!TablesCalls::run(static fn (): bool => (bool)$permissions->canAccessView($view, $userId))) {
					continue;
				}
				$found[$id] = ['view' => $view, 'via' => []];
			}
			if (!in_array($via, $found[$id]['via'], true)) {
				$found[$id]['via'][] = $via;
			}
		}
		uasort($found, static fn (array $a, array $b): int => [(int)$a['view']->getTableId(), (int)$a['view']->getId()] <=> [(int)$b['view']->getTableId(), (int)$b['view']->getId()]);
		return array_values($found);
	}

	/**
	 * Ids of the views in the user's applications.
	 *
	 * @param string $userId authenticated user UID
	 * @return list<int> view ids
	 * @throws ToolFailure when the Tables app failed
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	private function contextViewIds(string $userId): array {
		$contextService = $this->services->contextService($userId);
		$viewType = $this->services->viewNodeType($userId);
		$ids = [];
		foreach (TablesCalls::run(static fn (): array => array_values($contextService->findAll($userId))) as $context) {
			foreach ((array)$context->getNodes() as $node) {
				if (is_array($node) && (int)($node['node_type'] ?? -1) === $viewType && isset($node['node_id'])) {
					$ids[(int)$node['node_id']] = true;
				}
			}
		}
		return array_keys($ids);
	}
}
