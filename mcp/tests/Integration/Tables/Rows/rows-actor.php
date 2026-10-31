<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One user of the rows integration test, in its own CLI request (see RowsActor). Actions:
 * - pages: tables_list_rows through the real ToolRegistry, following nextCursor to the end (at most the input's
 *   `maxPages`, 50 by default); the session holds the input's `session` user (default: the user, "" for no session
 *   user), so a test can prove the guard;
 * - column-default: sets the text default of a column of the scene through ColumnMapper, as a column edit stores it,
 *   and answers the previous one, so a test can change the hidden column's default alone and restore it;
 * - native: what RowService::findAllByView()/findAllByTable() returns for the user (session = user), as formatRows() gives
 *   it, or the class of the exception the Tables app throws.
 */

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Tables\Rows\RowsCellScene;
use OCA\Mcp\Tools\Tables\TablesModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use Psr\Log\LoggerInterface;

require dirname(__DIR__, 2) . '/bootstrap.php';
[, $uid, $action] = $argv;
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$session = $in['session'] ?? $uid;
if (!in_array($uid, RowsCellScene::USERS, true) || ($session !== '' && !in_array($session, RowsCellScene::USERS, true))) {
	throw new RuntimeException('Not a user of the disposable rows scene');
}
$users = Server::get(IUserManager::class);
Server::get(IUserSession::class)->setUser($session === '' ? null : $users->get($session));
$apps = Server::get(IAppManager::class);
if (!$apps->isEnabledForAnyone('tables')) {
	throw new RuntimeException('Tables must be provisioned; this scenario never skips inside a Tables cell');
}
$apps->loadApp('tables');

switch ($action) {
	case 'pages':
		$policy = Server::get(GrantPolicy::class);
		$registry = new ToolRegistry([Server::get(TablesModule::class)], $policy, $apps, $users, Server::get(LoggerInterface::class));
		$pages = [];
		$arguments = $in['arguments'];
		for ($i = 0; $i < (int)($in['maxPages'] ?? 50); $i++) {
			try {
				$result = $policy->within($uid, static fn (): array => $registry->call('tables_list_rows', $arguments, $uid));
			} catch (InvalidArgumentException) {
				$pages[] = ['refused' => true];
				break;
			}
			$pages[] = $result;
			$page = ($result['isError'] ?? false) ? null : json_decode($result['content'][0]['text'], true);
			if (!is_array($page) || ($page['nextCursor'] ?? null) === null) {
				break;
			}
			$arguments['cursor'] = $page['nextCursor'];
		}
		$out = ['pages' => $pages];
		break;
	case 'column-default':
		$columnId = (int)$in['column'];
		if (!in_array($columnId, RowsCellScene::ids()['columns'], true)) {
			throw new RuntimeException('Not a column of the disposable rows scene');
		}
		$columns = Server::get('OCA\\Tables\\Db\\ColumnMapper');
		$entity = $columns->find($columnId);
		$out = ['previous' => $entity->getTextDefault()];
		$entity->setTextDefault($in['textDefault']);
		$columns->update($entity);
		break;
	case 'native':
		$rows = Server::get('OCA\\Tables\\Service\\RowService');
		try {
			$found = isset($in['view_id']) ? $rows->findAllByView((int)$in['view_id'], $uid, null, null) : $rows->findAllByTable((int)$in['table_id'], $uid, null, null);
			$out = ['rows' => array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'data' => $row['data']], $rows->formatRows($found))];
		} catch (Throwable $e) {
			$out = ['error' => $e::class];
		}
		break;
	default:
		throw new RuntimeException('Unknown action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
