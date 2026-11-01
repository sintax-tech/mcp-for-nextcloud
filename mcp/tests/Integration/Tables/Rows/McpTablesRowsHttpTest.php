<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tools\Tables\Rows\RowsMessages;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * tables_list_rows through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer), in a Tables cell: listed as a read, the viewer pages through the view with the cursor, and a change of the
 * view's saved filter between two pages, made by its owner through the Tables API, refuses the old cursor.
 */
#[Group('tables')]
final class McpTablesRowsHttpTest extends ExclusionTestCase {
	/**
	 * Basic and Bearer: listed as a read, two pages through the cursor, and the cursor refused once the view changed.
	 *
	 * @return void
	 */
	public function testTheEndpointPagesThroughTheView(): void {
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			$this->markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setEligible(RowsCellScene::VIEWER, true);
		$policy->setConnected(RowsCellScene::VIEWER, true);
		$ids = RowsCellScene::ids();
		$open = $ids['views']['open'];
		foreach (['basic', 'bearer'] as $kind) {
			$tool = array_values(array_filter($this->toolsList(RowsCellScene::VIEWER, $kind), static fn (array $t): bool => $t['name'] === 'tables_list_rows'));
			$this->assertCount(1, $tool, $kind);
			$this->assertTrue($tool[0]['annotations']['readOnlyHint'] ?? false, $kind);

			$client = $this->mcp(RowsCellScene::VIEWER, $kind);
			$first = $client->tool('tables_list_rows', ['view_id' => $open, 'limit' => 3]);
			$this->assertTrue($first->ok(), $first->describe());
			$this->assertTrue($first->json()['hasMore']);
			$second = $client->tool('tables_list_rows', ['view_id' => $open, 'limit' => 3, 'cursor' => $first->json()['nextCursor']]);
			$this->assertTrue($second->ok(), $second->describe());
			$this->assertFalse($second->json()['hasMore']);
			$all = [...array_column($first->json()['rows'], 'id'), ...array_column($second->json()['rows'], 'id')];
			$this->assertEqualsCanonicalizing([$ids['rows'][0], $ids['rows'][1], $ids['rows'][3], $ids['rows'][4], $ids['rows'][5]], $all, $kind);

			$this->setViewFilter($open, [[['columnId' => $ids['columns']['secret'], 'operator' => 'contains', 'value' => 'two']]]);
			try {
				$stale = $client->tool('tables_list_rows', ['view_id' => $open, 'limit' => 3, 'cursor' => $first->json()['nextCursor']]);
				$this->assertTrue($stale->isError(), $stale->describe());
				$this->assertSame(RowsMessages::staleCursor(), $stale->text());
			} finally {
				$this->setViewFilter($open, [[['columnId' => $ids['columns']['secret'], 'operator' => 'contains', 'value' => 'ok']]]);
			}
		}
	}

	/**
	 * Sets the saved filter of a view as its owner, through the Tables API.
	 *
	 * @param int $viewId the view
	 * @param list<list<array{columnId:int, operator:string, value:string}>> $filter the filter
	 * @return void
	 */
	private function setViewFilter(int $viewId, array $filter): void {
		$owner = LoginFactory::basic(RowsCellScene::OWNER);
		$data = ['filter' => json_encode($filter, JSON_THROW_ON_ERROR)];
		if (version_compare((string)getenv('MCP_IT_TABLES_VERSION'), '2.0.0', '<')) {
			$data += ['title' => '', 'description' => null, 'emoji' => '', 'columnSettings' => '', 'sort' => ''];
		}
		$response = Http::request('PUT', Http::BASE . "/index.php/apps/tables/api/1/views/$viewId", [
			'Authorization: ' . $owner->authorization(), 'Content-Type: application/json', 'Accept: application/json',
		], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
		$this->assertSame(200, $response['status'], $response['body']);
	}

	/**
	 * tools/list of a user over HTTP.
	 *
	 * @param string $uid the user
	 * @param string $kind basic or bearer
	 * @return list<array<string, mixed>> the tools
	 */
	private function toolsList(string $uid, string $kind): array {
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $this->login($uid, $kind)->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
		], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
		$this->assertSame(200, $response['status']);
		return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['result']['tools'];
	}
}
