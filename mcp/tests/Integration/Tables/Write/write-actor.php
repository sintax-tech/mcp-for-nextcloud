<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One user of the row write integration test, in its own CLI request (see WriteActor). Actions:
 * - call: one tools/call of tables_create_row or tables_update_row through the real ToolRegistry, with or without
 *   confirm; the session holds the input's `session` user (default: the user, "" for no session user); with
 *   `failAfterInsert` a listener of the Tables app's RowAddedEvent throws, after RowService wrote the row;
 * - native: every row of a table as RowService::findAllByTable() and formatRows() give it to the user (session = user);
 * - column-limit: sets the numberMax of a column of the scene through ColumnMapper, as a column edit stores it, and
 *   answers the previous one, so a test can change a limit between a plan and its confirmation and restore it.
 */

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCA\Mcp\Modules\Tables\TablesModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

require dirname(__DIR__, 2) . '/bootstrap.php';
[, $uid, $action] = $argv;
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$session = $in['session'] ?? $uid;
if (!in_array($uid, WriteCellScene::USERS, true) || ($session !== '' && !in_array($session, WriteCellScene::USERS, true))) {
	throw new RuntimeException('Not a user of the disposable row write scene');
}
$users = Server::get(IUserManager::class);
Server::get(IUserSession::class)->setUser($session === '' ? null : $users->get($session));
$apps = Server::get(IAppManager::class);
if (!$apps->isEnabledForAnyone('tables')) {
	throw new RuntimeException('Tables must be provisioned; this scenario never skips inside a Tables cell');
}
$apps->loadApp('tables');

switch ($action) {
	case 'call':
		if (!in_array($in['tool'], ['tables_create_row', 'tables_update_row'], true)) {
			throw new RuntimeException('Only the row write tools');
		}
		if (($in['failAfterInsert'] ?? false) === true) {
			Server::get(IEventDispatcher::class)->addListener('OCA\\Tables\\Event\\RowAddedEvent', static function (): void {
				throw new RuntimeException('listener failed after the insert');
			});
		}
		$policy = Server::get(GrantPolicy::class);
		$registry = new ToolRegistry([Server::get(TablesModule::class)], $policy, $apps, $users, Server::get(LoggerInterface::class));
		$result = $policy->within($uid, static fn (): array => $registry->call($in['tool'], $in['arguments'], $uid));
		$out = ['isError' => ($result['isError'] ?? false) === true, 'text' => (string)($result['content'][0]['text'] ?? ''),
			'structured' => $result['structuredContent'] ?? null];
		break;
	case 'native':
		$rows = Server::get('OCA\\Tables\\Service\\RowService');
		$out = ['rows' => array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'createdBy' => $row['createdBy'], 'lastEditBy' => $row['lastEditBy'],
			'data' => $row['data']], $rows->formatRows($rows->findAllByTable((int)$in['table_id'], $uid, null, null)))];
		break;
	case 'column-limit':
		$columnId = (int)$in['column'];
		if (!in_array($columnId, WriteCellScene::ids()['columns'], true)) {
			throw new RuntimeException('Not a column of the disposable row write scene');
		}
		$columns = Server::get('OCA\\Tables\\Db\\ColumnMapper');
		$entity = $columns->find($columnId);
		$out = ['previous' => $entity->getNumberMax()];
		$entity->setNumberMax($in['numberMax'] === null ? null : (float)$in['numberMax']);
		$columns->update($entity);
		break;
	default:
		throw new RuntimeException('Unknown action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
