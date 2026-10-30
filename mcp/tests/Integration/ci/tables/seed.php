<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Seeds the scene of the Tables tests through the Tables app's own public API, as its owner does from a client (the
 * same /api/1 routes and /api/2 contexts in 1.0.x and 2.x), and writes the ids it created to a JSON file:
 * - mcp-it-alice owns "Budget" (Item text, mandatory; Amount number; Status selection; Secret note text) and "Notes";
 * - view "Approved" of Budget shows Item and Amount (Amount read-only), filters on the hidden Secret note, and is shared
 *   with mcp-it-bob;
 * - Budget is shared with mcp-it-tables-carol to read only and with mcp-it-tables-frank to manage;
 * - view "Summary" of Budget shows Item and is the one node of the application "Team app", shared with
 *   mcp-it-tables-erin;
 * - mcp-it-tables-dave has nothing.
 * The app password it logs in with is revoked before it ends.
 *
 * Usage (inside the Nextcloud container, as the web server user, NEXTCLOUD_ROOT set): php seed.php <scene JSON file>
 */

use OC\Authentication\Token\IProvider;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\App\IAppManager;
use OCP\Server;

require dirname(__DIR__, 2) . '/bootstrap.php';

[, $out] = $argv + [null, null];
if (!is_string($out) || $out === '') {
	fwrite(STDERR, "usage: php seed.php <scene JSON file>\n");
	exit(2);
}
const OWNER = 'mcp-it-alice';
$login = LoginFactory::basic(OWNER);

/**
 * One call of the Tables API as the owner; fails on any status but 200.
 *
 * @param string $method HTTP method
 * @param string $path path after the server root
 * @param array<string, mixed> $body JSON body
 * @return array<string, mixed> the answer, without the OCS envelope
 */
$api = static function (string $method, string $path, array $body = []) use ($login): array {
	$response = Http::request($method, Http::BASE . $path, [
		'Authorization: ' . $login->authorization(), 'Content-Type: application/json', 'Accept: application/json', 'OCS-APIRequest: true',
	], json_encode($body, JSON_THROW_ON_ERROR));
	if ($response['status'] !== 200) {
		throw new RuntimeException("$method $path answered {$response['status']}: {$response['body']}");
	}
	$decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
	return $decoded['ocs']['data'] ?? $decoded;
};

/**
 * One column of a table, with the fields every supported release requires.
 *
 * @param int $tableId table id
 * @param array<string, mixed> $fields the column
 * @return int its id
 */
$column = static fn (int $tableId, array $fields): int => (int)$api('POST', "/index.php/apps/tables/api/1/tables/$tableId/columns", $fields + [
	'subtype' => '', 'mandatory' => false, 'description' => '',
])['id'];

/**
 * Shares a node with a user.
 *
 * @param int $nodeId node id
 * @param string $nodeType table, view or context
 * @param string $receiver user id
 * @param bool $manage whether the share gives manage
 * @return void
 */
$share = static function (int $nodeId, string $nodeType, string $receiver, bool $manage = false) use ($api): void {
	$api('POST', '/index.php/apps/tables/api/1/shares', ['nodeId' => $nodeId, 'nodeType' => $nodeType, 'receiver' => $receiver,
		'receiverType' => 'user', 'permissionRead' => true, 'permissionManage' => $manage]);
};

// ViewUpdateInput of 1.0.x reads every key of the update and skips the falsy ones; 2.3 reads every key that is present.
$legacyViewUpdate = version_compare((string)Server::get(IAppManager::class)->getAppVersion('tables'), '2.0.0', '<');

/**
 * Sets the columns, settings and filter of a view.
 *
 * @param int $viewId view id
 * @param list<array{columnId:int, order:int, readonly:bool, mandatory:bool}> $settings visible columns
 * @param list<list<array{columnId:int, operator:string, value:string}>> $filter filter groups
 * @return void
 */
$configure = static function (int $viewId, array $settings, array $filter) use ($api, $legacyViewUpdate): void {
	$data = ['columnSettings' => json_encode($settings, JSON_THROW_ON_ERROR)];
	if ($filter !== []) {
		$data['filter'] = json_encode($filter, JSON_THROW_ON_ERROR);
	}
	if ($legacyViewUpdate) {
		$data += ['title' => '', 'description' => null, 'emoji' => '', 'filter' => '', 'sort' => ''];
	}
	$api('PUT', "/index.php/apps/tables/api/1/views/$viewId", ['data' => $data]);
};

try {
	$budget = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Budget', 'emoji' => '💰'])['id'];
	$notes = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Notes', 'emoji' => null])['id'];
	$item = $column($budget, ['title' => 'Item', 'type' => 'text', 'subtype' => 'line', 'mandatory' => true, 'textMaxLength' => 200]);
	$amount = $column($budget, ['title' => 'Amount', 'type' => 'number', 'numberPrefix' => '€', 'numberMin' => 0, 'numberMax' => 100000, 'numberDecimals' => 2,
		'description' => 'In euros']);
	$status = $column($budget, ['title' => 'Status', 'type' => 'selection',
		'selectionOptions' => json_encode([['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed']], JSON_THROW_ON_ERROR), 'selectionDefault' => '1']);
	$secret = $column($budget, ['title' => 'Secret note', 'type' => 'text', 'subtype' => 'line']);

	$approved = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$budget/views", ['title' => 'Approved', 'emoji' => null])['id'];
	$configure($approved, [
		['columnId' => $item, 'order' => 0, 'readonly' => false, 'mandatory' => false],
		['columnId' => $amount, 'order' => 1, 'readonly' => true, 'mandatory' => false],
	], [[['columnId' => $secret, 'operator' => 'contains', 'value' => 'ok']]]);
	$summary = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$budget/views", ['title' => 'Summary', 'emoji' => null])['id'];
	$configure($summary, [['columnId' => $item, 'order' => 0, 'readonly' => false, 'mandatory' => false]], []);

	$share($approved, 'view', 'mcp-it-bob');
	$share($budget, 'table', 'mcp-it-tables-carol');
	$share($budget, 'table', 'mcp-it-tables-frank', true);
	$context = (int)$api('POST', '/ocs/v2.php/apps/tables/api/2/contexts', ['name' => 'Team app', 'iconName' => 'tablet', 'description' => '',
		'nodes' => [['id' => $summary, 'type' => 1, 'permissions' => 1]]])['id'];
	$share($context, 'context', 'mcp-it-tables-erin');

	$scene = [
		'tables' => ['budget' => $budget, 'notes' => $notes],
		'columns' => ['item' => $item, 'amount' => $amount, 'status' => $status, 'secret' => $secret],
		'views' => ['approved' => $approved, 'summary' => $summary],
		'context' => $context,
	];
	file_put_contents($out, json_encode($scene, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	echo 'Tables scene: ' . json_encode($scene, JSON_THROW_ON_ERROR) . "\n";
} finally {
	$provider = Server::get(IProvider::class);
	foreach ($provider->getTokenByUser(OWNER) as $token) {
		if ($token->getName() === 'mcp-it exclusion proof') {
			$provider->invalidateTokenById(OWNER, $token->getId());
		}
	}
}
