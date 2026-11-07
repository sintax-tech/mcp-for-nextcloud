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
 * - compare: both complete MCP pages and the native RowService result in this same actor request; neither is sorted
 *   by the harness. The scene declares Amount ASC, row-id ASC explicitly to make the native view order total;
 * - column-default: sets the text default of a column of the scene through ColumnMapper, as a column edit stores it,
 *   and answers the previous one, so a test can change the hidden column's default alone and restore it;
 * - native: what RowService::findAllByView()/findAllByTable() returns for the user (session = user), as formatRows() gives
 *   it, or the class of the exception the Tables app throws.
 */

use OCA\Mcp\Tests\Integration\Tables\Rows\RowsCellScene;
use OCA\Mcp\Tests\Integration\Tables\Rows\RowsActorReads;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

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
		$out = RowsActorReads::forUser($uid, $apps, $users)->pages($in);
		break;
	case 'compare':
		$out = RowsActorReads::forUser($uid, $apps, $users)->compare($in);
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
		$out = RowsActorReads::forUser($uid, $apps, $users)->native($in);
		break;
	default:
		throw new RuntimeException('Unknown action ' . $action);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
