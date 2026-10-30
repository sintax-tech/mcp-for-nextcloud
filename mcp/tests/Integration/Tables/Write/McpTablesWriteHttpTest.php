<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * tables_create_row through the authenticated HTTP endpoint, with an app password (Basic) and an OAuth access token
 * (Bearer), in a Tables cell: listed as a write only with the create grant, the call without confirm is the plan, and the
 * confirmed call with the plan's state creates the row, by the caller.
 */
#[Group('tables')]
final class McpTablesWriteHttpTest extends ExclusionTestCase {
	/**
	 * Basic and Bearer: hidden without the grant, listed as a write with it, planned, then created by the owner.
	 *
	 * @return void
	 */
	public function testTheEndpointPlansAndCreates(): void {
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			$this->markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setEligible(WriteCellScene::OWNER, true);
		$policy->setConnected(WriteCellScene::OWNER, true);
		$ids = WriteCellScene::ids();
		$arguments = ['table_id' => $ids['tables']['plans'], 'cells' => [['column_id' => $ids['columns']['title'], 'value' => 'Over HTTP']]];
		foreach (['basic', 'bearer'] as $kind) {
			$policy->setGrant(WriteCellScene::OWNER, 'tables', 'create', false);
			$this->assertSame([], $this->listed($kind), "$kind without the grant");
			$policy->setGrant(WriteCellScene::OWNER, 'tables', 'create', true);
			$tool = $this->listed($kind);
			$this->assertCount(1, $tool, $kind);
			$this->assertFalse($tool[0]['annotations']['readOnlyHint'] ?? true, $kind);

			$client = $this->mcp(WriteCellScene::OWNER, $kind);
			$plan = $client->tool('tables_create_row', $arguments);
			$this->assertTrue($plan->ok(), $plan->describe());
			$this->assertTrue($plan->structured()['requiresConfirmation'] ?? false, $kind);
			$created = $client->tool('tables_create_row', $arguments + ['confirm' => true, 'plan_state' => $plan->structured()['plan_state']]);
			$this->assertTrue($created->ok(), $created->describe());
			$rowId = (int)$created->json()['target']['rowId'];
			$row = array_values(array_filter(WriteActor::run(WriteCellScene::OWNER, 'native', ['table_id' => $ids['tables']['plans']])['rows'],
				static fn (array $row): bool => $row['id'] === $rowId));
			$this->assertSame(WriteCellScene::OWNER, $row[0]['createdBy'] ?? null, $kind);
		}
	}

	/**
	 * The tables_create_row entry of tools/list of the owner over HTTP.
	 *
	 * @param string $kind basic or bearer
	 * @return list<array<string, mixed>> the entry, or none
	 */
	private function listed(string $kind): array {
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $this->login(WriteCellScene::OWNER, $kind)->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
		], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
		$this->assertSame(200, $response['status']);
		$tools = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['result']['tools'];
		return array_values(array_filter($tools, static fn (array $tool): bool => $tool['name'] === 'tables_create_row'));
	}
}
