<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One user of the Tables integration test, in its own CLI request (see TablesActor). Actions:
 * - observe: the Tables tools listed for the user and the answers of tables_list_tables, tables_list_views and
 *   tables_list_columns for the table and view ids of the input, through the real ToolRegistry; the session holds the
 *   input's `session` user (default: the user), so a test can prove the answers follow the user passed, not the session;
 * - native: what the Tables app itself shows the user, through its own services with its own enrichment (the session
 *   holds the user): the tables with the views listed under each, the views shared with the user, the view nodes of the
 *   user's applications, and the columns of the input's tables and views, or null where the app refuses.
 */

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Tables\TablesCellScene;
use OCA\Mcp\Tools\Tables\TablesModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/bootstrap.php';
[, $uid, $action] = $argv;
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$session = $in['session'] ?? $uid;
if (!in_array($uid, TablesCellScene::USERS, true) || !in_array($session, TablesCellScene::USERS, true)) {
	throw new RuntimeException('Not a user of the disposable Tables scene');
}
$users = Server::get(IUserManager::class);
Server::get(IUserSession::class)->setUser($users->get($session));
$apps = Server::get(IAppManager::class);
if (!$apps->isEnabledForAnyone('tables')) {
	throw new RuntimeException('Tables must be provisioned; this scenario never skips inside a Tables cell');
}
$apps->loadApp('tables');

/**
 * Calls one tool through the registry, inside the policy operation of the user, as the endpoint does.
 *
 * @param ToolRegistry $registry registry with the Tables module
 * @param GrantPolicy $policy access policy
 * @param string $uid the user
 * @param string $tool tool name
 * @param array<string, mixed> $arguments tool arguments
 * @return array<string, mixed> the MCP result, or ['refused' => true] for a call the registry rejects
 */
function callTool(ToolRegistry $registry, GrantPolicy $policy, string $uid, string $tool, array $arguments): array {
	try {
		return $policy->within($uid, static fn (): array => $registry->call($tool, $arguments, $uid));
	} catch (InvalidArgumentException) {
		return ['refused' => true];
	}
}

/**
 * The ids of what a native call returns, or null when the Tables app refuses it.
 *
 * @param callable(): array<object> $call the call
 * @return list<int>|null the ids
 */
function nativeIds(callable $call): ?array {
	try {
		return array_map(static fn (object $entity): int => (int)$entity->getId(), array_values($call()));
	} catch (Throwable) {
		return null;
	}
}

switch ($action) {
	case 'observe':
		$policy = Server::get(GrantPolicy::class);
		$registry = new ToolRegistry([Server::get(TablesModule::class)], $policy, $apps, $users, Server::get(LoggerInterface::class));
		$columns = [];
		foreach ($in['tables'] ?? [] as $id) {
			$columns['table:' . $id] = callTool($registry, $policy, $uid, 'tables_list_columns', ['table_id' => $id]);
		}
		foreach ($in['views'] ?? [] as $id) {
			$columns['view:' . $id] = callTool($registry, $policy, $uid, 'tables_list_columns', ['view_id' => $id]);
		}
		$out = [
			'tools' => $policy->within($uid, static fn (): array => array_values(array_filter(array_column($registry->list($uid), 'name'), static fn (string $tool): bool => str_starts_with($tool, 'tables_')))),
			'tables' => callTool($registry, $policy, $uid, 'tables_list_tables', ['limit' => 200]),
			'views' => callTool($registry, $policy, $uid, 'tables_list_views', ['limit' => 200]),
			'columns' => $columns,
		];
		break;
	case 'native':
		if ($session !== $uid) {
			throw new RuntimeException('The native view is read with the user in the session, as the Tables app reads it');
		}
		$tables = Server::get('OCA\\Tables\\Service\\TableService');
		$views = Server::get('OCA\\Tables\\Service\\ViewService');
		$columnService = Server::get('OCA\\Tables\\Service\\ColumnService');
		$listed = [];
		foreach ($tables->findAll($uid, false, false, false) as $table) {
			$listed[(int)$table->getId()] = $table->getViews() === null ? null : array_map(static fn (object $view): int => (int)$view->getId(), $table->getViews());
		}
		ksort($listed);
		$contextViews = [];
		foreach (Server::get('OCA\\Tables\\Service\\ContextService')->findAll($uid) as $context) {
			foreach ((array)$context->getNodes() as $node) {
				if ((int)$node['node_type'] === 1) {
					$contextViews[] = (int)$node['node_id'];
				}
			}
		}
		$columns = [];
		foreach ($in['tables'] ?? [] as $id) {
			$columns['table:' . $id] = nativeIds(static fn (): array => $columnService->findAllByTable($id, $uid));
		}
		foreach ($in['views'] ?? [] as $id) {
			$columns['view:' . $id] = nativeIds(static fn (): array => $columnService->findAllByView($id, $uid));
		}
		$out = [
			'tables' => $listed,
			'shared' => nativeIds(static fn (): array => $views->findSharedViewsWithMe($uid)) ?? [],
			'contextViews' => $contextViews,
			'columns' => $columns,
		];
		break;
	default:
		throw new RuntimeException('Unknown action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
