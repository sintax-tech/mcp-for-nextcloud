<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Tables tools through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer), in a Tables cell: listed as reads, alice gets her two tables with their links, bob gets the one view shared
 * with him and its two columns, and the table behind it reads exactly like a missing one.
 */
#[Group('tables')]
final class McpTablesHttpTest extends ExclusionTestCase {
	/** The tools of the module. */
	private const TOOLS = ['tables_list_tables', 'tables_list_views', 'tables_list_columns'];

	/**
	 * Basic and Bearer: listed as reads, the owner's tables, the shared view and its columns, the table out of reach.
	 *
	 * @return void
	 */
	public function testTheEndpointListsWhatEachUserCanOpen(): void {
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			$this->markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		$ids = TablesCellScene::ids();
		foreach (['basic', 'bearer'] as $kind) {
			foreach ([self::ALICE, self::BOB] as $uid) {
				$tools = array_values(array_filter($this->toolsList($uid, $kind), static fn (array $tool): bool => in_array($tool['name'], self::TOOLS, true)));
				$this->assertSame(self::TOOLS, array_column($tools, 'name'), "$kind $uid");
				foreach ($tools as $tool) {
					$this->assertTrue($tool['annotations']['readOnlyHint'] ?? false, "$kind {$tool['name']}");
				}
			}

			$alice = $this->mcp(self::ALICE, $kind)->tool('tables_list_tables');
			$this->assertTrue($alice->ok(), $alice->describe());
			$this->assertSame([$ids['tables']['budget'], $ids['tables']['notes']], array_column($alice->json()['tables'], 'id'));
			$this->assertStringEndsWith('/apps/tables/#/table/' . $ids['tables']['budget'], $alice->json()['tables'][0]['url']);

			$bob = $this->mcp(self::BOB, $kind);
			$views = $bob->tool('tables_list_views');
			$this->assertTrue($views->ok(), $views->describe());
			$this->assertSame([$ids['views']['approved']], array_column($views->json()['views'], 'id'));
			$this->assertStringEndsWith('/apps/tables/#/view/' . $ids['views']['approved'], $views->json()['views'][0]['url']);
			$columns = $bob->tool('tables_list_columns', ['view_id' => $ids['views']['approved']]);
			$this->assertTrue($columns->ok(), $columns->describe());
			$this->assertSame([$ids['columns']['item'], $ids['columns']['amount']], array_column($columns->json()['columns'], 'id'));
			$table = $bob->tool('tables_list_columns', ['table_id' => $ids['tables']['budget']]);
			$missing = $bob->tool('tables_list_columns', ['table_id' => TablesCellScene::MISSING]);
			$this->assertTrue($table->isError(), $table->describe());
			$this->assertSame($missing->fingerprint(), $table->fingerprint());
			$this->assertSame([], $bob->tool('tables_list_tables')->json()['tables']);
		}
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
