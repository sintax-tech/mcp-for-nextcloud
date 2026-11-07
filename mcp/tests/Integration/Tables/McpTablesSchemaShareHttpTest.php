<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Tables\Integration\TablesWriteCapabilities;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient};
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Minimal real Bearer proof of Tables schema and sharing, using only disposable harness actors. */
#[Group('tables')]
final class McpTablesSchemaShareHttpTest extends IntegrationTestCase {
	/** Selects the explicit Tables cell and its supported schema version family.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			self::markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		if (Server::get(TablesWriteCapabilities::class)->profile() === null) {
			self::markTestSkipped('Installed Tables version is outside the proven schema/sharing ranges.');
		}
	}

	/** Plans and confirms each schema/share call, rereads it and refuses both independent grants when absent.
	 * @return void
	 */
	public function testBearerSchemaAndShareRoundtripAndGrantRefusals(): void {
		$uid = WriteCellScene::OWNER;
		$policy = Server::get(GrantPolicy::class);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		$policy->setGrant($uid, 'tables', 'read', true);
		$client = new McpClient(LoginFactory::bearer($uid));
		try {
			$args = ['title' => 'HTTP schema proof'];
			$policy->setGrant($uid, 'tables', 'schema', false);
			self::assertFalse($client->tool('tables_create_table', $args)->ok());
			$policy->setGrant($uid, 'tables', 'schema', true);
			$table = $this->apply($client, 'tables_create_table', $args)['observed'];
			$id = $table['id'];
			$tables = $client->tool('tables_list_tables');
			self::assertTrue($tables->ok(), $tables->describe());
			self::assertContains($id, array_column($tables->json()['tables'], 'id'));
			$column = $this->apply($client, 'tables_create_column', ['table_id' => $id, 'title' => 'Label', 'type' => 'text', 'subtype' => 'line'])['observed'];
			$columns = $client->tool('tables_list_columns', ['table_id' => $id]);
			self::assertTrue($columns->ok(), $columns->describe());
			self::assertSame([$column['id']], array_column($columns->json()['columns'], 'id'));
			$view = $this->apply($client, 'tables_create_view', ['table_id' => $id, 'title' => 'HTTP view'])['observed'];
			$views = $client->tool('tables_list_views');
			self::assertTrue($views->ok(), $views->describe());
			self::assertContains($view['id'], array_column($views->json()['views'], 'id'));

			$shareArgs = ['node_type' => 'table', 'node_id' => $id,
				'recipient' => ['type' => 'user', 'id' => WriteCellScene::READER],
				'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]];
			$policy->setGrant($uid, 'tables', 'share', false);
			self::assertFalse($client->tool('tables_share_node', $shareArgs)->ok());
			$policy->setGrant($uid, 'tables', 'share', true);
			$share = $this->apply($client, 'tables_share_node', $shareArgs)['observed'];
			self::assertSame($shareArgs['recipient'], $share['recipient']);
			self::assertSame($shareArgs['permissions'], $share['permissions']);
			$reader = new McpClient(LoginFactory::bearer(WriteCellScene::READER));
			$visible = $reader->tool('tables_list_columns', ['table_id' => $id]);
			self::assertTrue($visible->ok(), $visible->describe());
			self::assertSame([$column['id']], array_column($visible->json()['columns'], 'id'));
			$revoked = $this->apply($client, 'tables_unshare_node', ['node_type' => 'table', 'node_id' => $id, 'share_id' => $share['id']]);
			self::assertSame([], $revoked['observed']);
			self::assertFalse($reader->tool('tables_list_columns', ['table_id' => $id])->ok());
		} finally {
			foreach (['schema', 'share'] as $operation) {
				$policy->setGrant($uid, 'tables', $operation, false);
			}
		}
	}

	/** Confirms exactly the state returned by one inert preview.
	 * @param McpClient $client Authenticated actor.
	 * @param string $tool Published schema/share tool.
	 * @param array<string,mixed> $args Request.
	 * @return array<string,mixed> Verified receipt.
	 */
	private function apply(McpClient $client, string $tool, array $args): array {
		$plan = $client->tool($tool, $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		$reply = $client->tool($tool, $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan->structured()['plan_state']]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame('observed', $reply->json()['status'], $reply->describe());
		return $reply->json();
	}
}
