<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Seeds the scene of the Tables row write tests through the Tables app's own public API, as its owner does from a client,
 * and writes the ids it created to a JSON file. Its users are its own, so the discovery and rows scenes stay as they are:
 * - mcp-it-write-owner owns "Plans" (Title text, mandatory, at most 50; Amount number with 2 decimals, 0..1000; Status
 *   selection 1 Open, 2 Closed; Secret text; Tags multiple selection 1 red, 10 Blue; Done yes/no; Rating stars; Due date;
 *   Site link with the url provider; Notes text; Level stars limited to 3..4) and "Elsewhere" (one text column);
 * - two rows: "Alpha" (Amount 10, Secret "ok"), "Beta" (Secret "no");
 * - view "Write view" shows Title, Amount, Status, Tags, Done and Notes, Notes read-only, filters on the hidden Secret
 *   (is-equal "ok"), so a row created through it gets Secret "ok"; shared with mcp-it-write-viewer to create rows only and
 *   with mcp-it-write-editor to read and update;
 * - view "Foreign write view" filters on the column of "Elsewhere", another table; shared with mcp-it-write-viewer to create;
 * - views "Stars view" and "Stars bound view" show Title and filter on the hidden Level (is-equal "@stars-1", outside the
 *   column's limits, and "@stars-3", at its minimum);
 * - "Plans" is shared with mcp-it-write-reader to read only and with mcp-it-write-shared to read, create and update.
 * The app password it logs in with is revoked before it ends.
 *
 * Usage (inside the Nextcloud container, as the web server user, NEXTCLOUD_ROOT set): php seed-write.php <scene JSON file>
 */

use OC\Authentication\Token\IProvider;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\App\IAppManager;
use OCP\Server;

require dirname(__DIR__, 2) . '/bootstrap.php';

[, $out] = $argv + [null, null];
if (!is_string($out) || $out === '') {
	fwrite(STDERR, "usage: php seed-write.php <scene JSON file>\n");
	exit(2);
}
const WRITE_OWNER = 'mcp-it-write-owner';
$login = LoginFactory::basic(WRITE_OWNER);

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
 * @param string $nodeType table or view
 * @param string $receiver user id
 * @param bool $read whether the share allows reading
 * @param bool $create whether the share allows creating rows
 * @param bool $update whether the share allows updating rows
 * @return void
 */
$share = static function (int $nodeId, string $nodeType, string $receiver, bool $read, bool $create, bool $update) use ($api): void {
	$api('POST', '/index.php/apps/tables/api/1/shares', ['nodeId' => $nodeId, 'nodeType' => $nodeType, 'receiver' => $receiver,
		'receiverType' => 'user', 'permissionRead' => $read, 'permissionCreate' => $create, 'permissionUpdate' => $update]);
};

// ViewUpdateInput of 1.0.x reads every key of the update and skips the falsy ones; 2.3 reads every key that is present.
$legacyViewUpdate = version_compare((string)Server::get(IAppManager::class)->getAppVersion('tables'), '2.0.0', '<');

/**
 * Sets the columns, their read-only setting, the filter and the sort of a view.
 *
 * @param int $viewId view id
 * @param list<int> $columns visible columns, in order
 * @param list<int> $readonly the visible columns that are read-only
 * @param list<list<array{columnId:int, operator:string, value:string}>> $filter filter groups
 * @param list<array{columnId:int, mode:string}> $sort sort rules
 * @return void
 */
$configure = static function (int $viewId, array $columns, array $readonly, array $filter, array $sort) use ($api, $legacyViewUpdate): void {
	$settings = array_map(static fn (int $id, int $order): array => ['columnId' => $id, 'order' => $order, 'readonly' => in_array($id, $readonly, true), 'mandatory' => false],
		$columns, array_keys($columns));
	$data = ['columnSettings' => json_encode($settings, JSON_THROW_ON_ERROR), 'filter' => json_encode($filter, JSON_THROW_ON_ERROR)];
	if ($sort !== []) {
		$data['sort'] = json_encode($sort, JSON_THROW_ON_ERROR);
	}
	if ($legacyViewUpdate) {
		$data += ['title' => '', 'description' => null, 'emoji' => '', 'sort' => ''];
	}
	$api('PUT', "/index.php/apps/tables/api/1/views/$viewId", ['data' => $data]);
};

try {
	$plans = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Plans', 'emoji' => null])['id'];
	$elsewhereTable = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Elsewhere', 'emoji' => null])['id'];
	$title = $column($plans, ['title' => 'Title', 'type' => 'text', 'subtype' => 'line', 'mandatory' => true, 'textMaxLength' => 50]);
	$amount = $column($plans, ['title' => 'Amount', 'type' => 'number', 'numberDecimals' => 2, 'numberMin' => 0, 'numberMax' => 1000]);
	$status = $column($plans, ['title' => 'Status', 'type' => 'selection',
		'selectionOptions' => json_encode([['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed']], JSON_THROW_ON_ERROR)]);
	$secret = $column($plans, ['title' => 'Secret', 'type' => 'text', 'subtype' => 'line']);
	$tags = $column($plans, ['title' => 'Tags', 'type' => 'selection', 'subtype' => 'multi',
		'selectionOptions' => json_encode([['id' => 1, 'label' => 'red'], ['id' => 10, 'label' => 'Blue']], JSON_THROW_ON_ERROR)]);
	$done = $column($plans, ['title' => 'Done', 'type' => 'selection', 'subtype' => 'check']);
	$rating = $column($plans, ['title' => 'Rating', 'type' => 'number', 'subtype' => 'stars']);
	$due = $column($plans, ['title' => 'Due', 'type' => 'datetime', 'subtype' => 'date']);
	$site = $column($plans, ['title' => 'Site', 'type' => 'text', 'subtype' => 'link', 'textAllowedPattern' => 'url']);
	$notes = $column($plans, ['title' => 'Notes', 'type' => 'text', 'subtype' => 'line']);
	$level = $column($plans, ['title' => 'Level', 'type' => 'number', 'subtype' => 'stars', 'numberMin' => 3, 'numberMax' => 4]);
	$foreign = $column($elsewhereTable, ['title' => 'Far', 'type' => 'text', 'subtype' => 'line']);

	$rows = [];
	foreach ([[$title => 'Alpha', $amount => 10, $secret => 'ok'], [$title => 'Beta', $secret => 'no']] as $data) {
		$rows[] = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$plans/rows", ['data' => $data])['id'];
	}

	$view = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$plans/views", ['title' => 'Write view', 'emoji' => null])['id'];
	$configure($view, [$title, $amount, $status, $tags, $done, $notes], [$notes], [[['columnId' => $secret, 'operator' => 'is-equal', 'value' => 'ok']]],
		[['columnId' => $amount, 'mode' => 'ASC']]);
	$foreignView = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$plans/views", ['title' => 'Foreign write view', 'emoji' => null])['id'];
	$configure($foreignView, [$title], [], [[['columnId' => $foreign, 'operator' => 'is-equal', 'value' => 'x']]], []);
	$starsView = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$plans/views", ['title' => 'Stars view', 'emoji' => null])['id'];
	$configure($starsView, [$title], [], [[['columnId' => $level, 'operator' => 'is-equal', 'value' => '@stars-1']]], []);
	$starsBoundView = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$plans/views", ['title' => 'Stars bound view', 'emoji' => null])['id'];
	$configure($starsBoundView, [$title], [], [[['columnId' => $level, 'operator' => 'is-equal', 'value' => '@stars-3']]], []);

	$share($view, 'view', 'mcp-it-write-viewer', false, true, false);
	$share($view, 'view', 'mcp-it-write-editor', true, false, true);
	$share($foreignView, 'view', 'mcp-it-write-viewer', false, true, false);
	$share($plans, 'table', 'mcp-it-write-reader', true, false, false);
	$share($plans, 'table', 'mcp-it-write-shared', true, true, true);

	$scene = [
		'tables' => ['plans' => $plans, 'elsewhere' => $elsewhereTable],
		'columns' => ['title' => $title, 'amount' => $amount, 'status' => $status, 'secret' => $secret, 'tags' => $tags, 'done' => $done,
			'rating' => $rating, 'due' => $due, 'site' => $site, 'notes' => $notes, 'level' => $level, 'foreign' => $foreign],
		'rows' => $rows,
		'views' => ['write' => $view, 'foreign' => $foreignView, 'stars' => $starsView, 'starsBound' => $starsBoundView],
	];
	file_put_contents($out, json_encode($scene, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	echo 'Tables write scene: ' . json_encode($scene, JSON_THROW_ON_ERROR) . "\n";
} finally {
	$provider = Server::get(IProvider::class);
	foreach ($provider->getTokenByUser(WRITE_OWNER) as $token) {
		if ($token->getName() === 'mcp-it exclusion proof') {
			$provider->invalidateTokenById(WRITE_OWNER, $token->getId());
		}
	}
}
