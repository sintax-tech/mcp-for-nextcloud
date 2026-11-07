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
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, ServiceSwitchFixture};
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/** Minimal real Bearer proof of Tables schema and sharing, using only disposable harness actors. */
#[Group('tables')]
final class McpTablesSchemaShareHttpTest extends IntegrationTestCase {
	/** @var ServiceSwitchFixture|null Per-test service switch, absent when the cell is skipped. */
	private ?ServiceSwitchFixture $serviceSwitch = null;

	/** @var TablesSchemaHttpActors|null Actor preferences captured before any setup mutation. */
	private ?TablesSchemaHttpActors $actors = null;

	/** Selects the explicit Tables cell.
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			self::markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		$this->serviceSwitch = ServiceSwitchFixture::open();
		try {
			$this->actors = new TablesSchemaHttpActors(Server::get(GrantPolicy::class), Server::get(IConfig::class));
			$this->actors->enable();
		} catch (Throwable $error) {
			$this->restoreAccess();
			throw $error;
		}
	}

	/** @return void Restore both actors and the switch even if setup, consent or an assertion fails. */
	protected function tearDown(): void {
		try {
			$this->restoreAccess();
		} finally {
			parent::tearDown();
		}
	}

	/** @return void Actor restoration always precedes restoration of the global service switch. */
	private function restoreAccess(): void {
		try {
			$this->actors?->restore();
		} finally {
			$this->serviceSwitch?->restore();
		}
	}

	/** Plans and confirms each schema/share call, rereads it and refuses both independent grants when absent.
	 * @return void
	 */
	public function testBearerSchemaAndShareRoundtripAndGrantRefusals(): void {
		$uid = WriteCellScene::OWNER;
		$policy = Server::get(GrantPolicy::class);
		$client = new McpClient(LoginFactory::bearer($uid));
		$native = new TablesSchemaScene();
		$id = null;
		try {
			$args = ['title' => 'HTTP schema proof'];
			$capabilities = Server::get(TablesWriteCapabilities::class);
			if ($capabilities->profile() === null) {
				foreach (['schema', 'share'] as $operation) {
					$policy->setGrant($uid, 'tables', $operation, true);
				}
				foreach (['tables.native_schema_api', 'tables.native_sharing_api'] as $key) {
					self::assertFalse($capabilities->supports($key));
				}
				$requests = [
					['tables_create_table', $args],
					['tables_share_node', ['node_type' => 'table', 'node_id' => WriteCellScene::ids()['tables']['plans'],
						'recipient' => ['type' => 'user', 'id' => WriteCellScene::READER],
						'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]]],
				];
				foreach ($requests as [$tool, $request]) {
					$before = TablesSchemaScene::snapshot();
					$reply = $client->tool($tool, $request);
					self::assertFalse($reply->ok());
					self::assertSame('Unknown tool', $reply->body['error']['message'] ?? null);
					self::assertSame($before, TablesSchemaScene::snapshot());
				}
				return;
			}
			$policy->setGrant($uid, 'tables', 'schema', false);
			$before = TablesSchemaScene::snapshot();
			self::assertFalse($client->tool('tables_create_table', $args)->ok());
			self::assertSame($before, TablesSchemaScene::snapshot());
			$policy->setGrant($uid, 'tables', 'schema', true);
			$table = $this->apply($client, 'tables_create_table', $args)['observed'];
			$id = $table['id'];
			self::assertSame('HTTP schema proof', $native->api('GET', "tables/$id")['title']);
			$column = $this->apply($client, 'tables_create_column', ['table_id' => $id, 'title' => 'Label', 'type' => 'text', 'subtype' => 'line'])['observed'];
			$columns = $native->api('GET', "tables/$id/columns");
			self::assertSame([$column['id']], array_column($columns, 'id'));
			self::assertSame('text', $columns[0]['type']);
			self::assertSame('line', $columns[0]['subtype']);
			$view = $this->apply($client, 'tables_create_view', ['table_id' => $id, 'title' => 'HTTP view'])['observed'];
			$nativeView = $native->api('GET', 'views/' . $view['id']);
			self::assertSame($id, $nativeView['tableId']);
			self::assertSame('HTTP view', $nativeView['title']);
			Tables240HttpProof::run($client, $native, $id, $column['id'], $view['id']);

			$shareArgs = ['node_type' => 'table', 'node_id' => $id,
				'recipient' => ['type' => 'user', 'id' => WriteCellScene::READER],
				'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]];
			$policy->setGrant($uid, 'tables', 'share', false);
			$before = TablesSchemaScene::snapshot();
			self::assertFalse($client->tool('tables_share_node', $shareArgs)->ok());
			self::assertSame($before, TablesSchemaScene::snapshot());
			$policy->setGrant($uid, 'tables', 'share', true);
			$share = $this->apply($client, 'tables_share_node', $shareArgs)['observed'];
			self::assertSame($shareArgs['recipient'], $share['recipient']);
			self::assertSame($shareArgs['permissions'], $share['permissions']);
			$nativeShares = $native->api('GET', "tables/$id/shares");
			$nativeShare = array_values(array_filter($nativeShares, static fn (array $s): bool => $s['id'] === $share['id']));
			self::assertCount(1, $nativeShare);
			self::assertSame(WriteCellScene::READER, $nativeShare[0]['receiver']);
			foreach ($shareArgs['permissions'] as $permission => $expected) {
				self::assertSame($expected, (bool)$nativeShare[0]['permission' . ucfirst($permission)]);
			}
			$reader = new McpClient(LoginFactory::bearer(WriteCellScene::READER));
			$visible = $reader->tool('tables_list_columns', ['table_id' => $id]);
			self::assertTrue($visible->ok(), $visible->describe());
			self::assertSame([$column['id']], array_column($visible->json()['columns'], 'id'));
			$revoked = $this->apply($client, 'tables_unshare_node', ['node_type' => 'table', 'node_id' => $id, 'share_id' => $share['id']]);
			self::assertSame([], $revoked['observed']);
			self::assertNotContains($share['id'], array_column($native->api('GET', "tables/$id/shares"), 'id'));
			$before = TablesSchemaScene::snapshot();
			self::assertFalse($reader->tool('tables_list_columns', ['table_id' => $id])->ok());
			self::assertSame($before, TablesSchemaScene::snapshot());
		} finally {
			if ($id !== null) {
				$native->api('DELETE', "tables/$id");
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
		$before = TablesSchemaScene::snapshot();
		$plan = $client->tool($tool, $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		self::assertSame($before, TablesSchemaScene::snapshot());
		$reply = $client->tool($tool, $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan->structured()['plan_state']]);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame('observed', $reply->json()['status'], $reply->describe());
		return $reply->json();
	}
}
