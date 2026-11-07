<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Seeds the rows scene of the Tables row tests through the Tables app's own public API, as its owner does from a client,
 * and writes the ids it created to a JSON file. Its users are its own, so the discovery scene of seed.php stays as it is:
 * - mcp-it-rows-owner owns "Events" (Item text; Amount number; Status single selection 1 Open, 2 Closed, 10 Árvore,
 *   default 1; Secret note text; Tags multiple selection 1 red, 10 Blue; Notes long text) and "Other" (one text
 *   column);
 * - six rows: ties on Amount, Status 1 and 10, one row without Status (its default applies), Tags [1], [10], [1,10];
 *   one row whose only "needle" is in the Secret note; Notes of the first row is a long text with line breaks within the
 *   cell budget of tables_list_rows, of the second a longer text past it;
 * - view "Open events" shows Item, Amount, Status and Tags (not Notes), filters on the hidden Secret note (contains "ok"), sorts by
 *   Amount then row id (both ASC), and is shared with mcp-it-rows-viewer and is the one node of the application "Rows app" of
 *   mcp-it-rows-member;
 * - view "Broken" filters on the column of "Other", another table, and is shared with mcp-it-rows-viewer;
 * - "Events" is shared with mcp-it-rows-reader to read and with mcp-it-rows-creator to create rows only.
 * The app password it logs in with is revoked before it ends.
 *
 * Usage (inside the Nextcloud container, as the web server user, NEXTCLOUD_ROOT set): php seed-rows.php <scene JSON file>
 */

use OC\Authentication\Token\IProvider;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCP\App\IAppManager;
use OCP\Server;

require dirname(__DIR__, 2) . '/bootstrap.php';

[, $out] = $argv + [null, null];
if (!is_string($out) || $out === '') {
	fwrite(STDERR, "usage: php seed-rows.php <scene JSON file>\n");
	exit(2);
}
const ROWS_OWNER = 'mcp-it-rows-owner';
$login = LoginFactory::basic(ROWS_OWNER);

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
 * @param bool $read whether the share allows reading
 * @param bool $create whether the share allows creating rows
 * @return void
 */
$share = static function (int $nodeId, string $nodeType, string $receiver, bool $read = true, bool $create = false) use ($api): void {
	$api('POST', '/index.php/apps/tables/api/1/shares', ['nodeId' => $nodeId, 'nodeType' => $nodeType, 'receiver' => $receiver,
		'receiverType' => 'user', 'permissionRead' => $read, 'permissionCreate' => $create]);
};

// ViewUpdateInput of 1.0.x reads every key of the update and skips the falsy ones; 2.3 reads every key that is present.
$legacyViewUpdate = version_compare((string)Server::get(IAppManager::class)->getAppVersion('tables'), '2.0.0', '<');

/**
 * Sets the columns, filter and sort of a view.
 *
 * @param int $viewId view id
 * @param list<int> $columns visible columns, in order
 * @param list<list<array{columnId:int, operator:string, value:string}>> $filter filter groups
 * @param list<array{columnId:int, mode:string}> $sort sort rules
 * @return void
 */
$configure = static function (int $viewId, array $columns, array $filter, array $sort) use ($api, $legacyViewUpdate): void {
	$settings = array_map(static fn (int $id, int $order): array => ['columnId' => $id, 'order' => $order, 'readonly' => false, 'mandatory' => false], $columns, array_keys($columns));
	$data = ['columnSettings' => json_encode($settings, JSON_THROW_ON_ERROR)];
	if ($filter !== []) {
		$data['filter'] = json_encode($filter, JSON_THROW_ON_ERROR);
	}
	if ($sort !== []) {
		$data['sort'] = json_encode($sort, JSON_THROW_ON_ERROR);
	}
	if ($legacyViewUpdate) {
		$data += ['title' => '', 'description' => null, 'emoji' => '', 'filter' => '', 'sort' => ''];
	}
	$api('PUT', "/index.php/apps/tables/api/1/views/$viewId", ['data' => $data]);
};

try {
	$events = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Events', 'emoji' => null])['id'];
	$other = (int)$api('POST', '/index.php/apps/tables/api/1/tables', ['title' => 'Other', 'emoji' => null])['id'];
	$item = $column($events, ['title' => 'Item', 'type' => 'text', 'subtype' => 'line']);
	$amount = $column($events, ['title' => 'Amount', 'type' => 'number', 'numberDecimals' => 0]);
	$status = $column($events, ['title' => 'Status', 'type' => 'selection', 'selectionDefault' => '1',
		'selectionOptions' => json_encode([['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed'], ['id' => 10, 'label' => "A\u{0301}rvore"]], JSON_THROW_ON_ERROR)]);
	$secret = $column($events, ['title' => 'Secret note', 'type' => 'text', 'subtype' => 'line']);
	$tags = $column($events, ['title' => 'Tags', 'type' => 'selection', 'subtype' => 'multi',
		'selectionOptions' => json_encode([['id' => 1, 'label' => 'red'], ['id' => 10, 'label' => 'Blue']], JSON_THROW_ON_ERROR)]);
	$notes = $column($events, ['title' => 'Notes', 'type' => 'text', 'subtype' => 'long']);
	$elsewhere = $column($other, ['title' => 'Elsewhere', 'type' => 'text', 'subtype' => 'line']);
	$longNotes = [implode("\n", array_fill(0, 250, 'Nota longa com acentuação ç')), str_repeat('0123456789', 2600)];

	$rows = [];
	foreach ([
		['Alpha', 10, 1, 'ok one', [1]],
		['Beta', 20, 10, 'ok two', [10]],
		['Gamma', 10, 2, 'no', [1, 10]],
		['Delta', 30, null, 'ok three', null],
		['Secretive', 5, 2, 'needle ok', null],
		['Epsilon', 10, 1, 'ok four', [10]],
	] as [$itemValue, $amountValue, $statusValue, $secretValue, $tagsValue]) {
		$data = array_filter([$item => $itemValue, $amount => $amountValue, $status => $statusValue, $secret => $secretValue, $tags => $tagsValue],
			static fn (mixed $value): bool => $value !== null);
		if (isset($longNotes[count($rows)])) {
			$data[$notes] = $longNotes[count($rows)];
		}
		$rows[] = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$events/rows", ['data' => $data])['id'];
	}

	$open = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$events/views", ['title' => 'Open events', 'emoji' => null])['id'];
	$configure($open, [$item, $amount, $status, $tags], [[['columnId' => $secret, 'operator' => 'contains', 'value' => 'ok']]], [['columnId' => $amount, 'mode' => 'ASC'], ['columnId' => -1, 'mode' => 'ASC']]);
	$broken = (int)$api('POST', "/index.php/apps/tables/api/1/tables/$events/views", ['title' => 'Broken', 'emoji' => null])['id'];
	$configure($broken, [$item], [[['columnId' => $elsewhere, 'operator' => 'contains', 'value' => 'x']]], []);

	$share($open, 'view', 'mcp-it-rows-viewer');
	$share($broken, 'view', 'mcp-it-rows-viewer');
	$share($events, 'table', 'mcp-it-rows-reader');
	$share($events, 'table', 'mcp-it-rows-creator', false, true);
	$context = (int)$api('POST', '/ocs/v2.php/apps/tables/api/2/contexts', ['name' => 'Rows app', 'iconName' => 'tablet', 'description' => '',
		'nodes' => [['id' => $open, 'type' => 1, 'permissions' => 1]]])['id'];
	$share($context, 'context', 'mcp-it-rows-member');

	$scene = [
		'tables' => ['events' => $events, 'other' => $other],
		'columns' => ['item' => $item, 'amount' => $amount, 'status' => $status, 'secret' => $secret, 'tags' => $tags, 'notes' => $notes, 'elsewhere' => $elsewhere],
		'rows' => $rows,
		'views' => ['open' => $open, 'broken' => $broken],
		'context' => $context,
	];
	file_put_contents($out, json_encode($scene, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
	echo 'Tables rows scene: ' . json_encode($scene, JSON_THROW_ON_ERROR) . "\n";
} finally {
	$provider = Server::get(IProvider::class);
	foreach ($provider->getTokenByUser(ROWS_OWNER) as $token) {
		if ($token->getName() === 'mcp-it exclusion proof') {
			$provider->invalidateTokenById(ROWS_OWNER, $token->getId());
		}
	}
}
