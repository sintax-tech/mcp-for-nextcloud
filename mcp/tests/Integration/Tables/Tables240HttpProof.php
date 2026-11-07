<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use PHPUnit\Framework\Assert;

/** Adds a real 2.4.0-only native card-preservation proof to the existing disposable schema HTTP round trip. */
final class Tables240HttpProof {
	/**
	 * @param McpClient $client Existing schema-enabled owner Bearer client.
	 * @param TablesSchemaScene $native Independent native observations and cleanup.
	 * @param int $table Disposable table created by the parent scenario.
	 * @param int $column Existing visible source column.
	 * @param int $view Disposable view created by the parent scenario.
	 * @return void Existing pins keep their original assertions; the explicit 2.4.0 cell also verifies cards.
	 */
	public static function run(McpClient $client, TablesSchemaScene $native, int $table, int $column, int $view): void {
		if (getenv('MCP_IT_TABLES_VERSION') !== '2.4.0') return;
		$extra = self::apply($client, 'tables_create_column', ['table_id' => $table, 'title' => 'Other', 'type' => 'text', 'subtype' => 'line'])['id'];
		try {
			$settings = ['cardBackgroundSource' => $column, 'cardTitleSource' => $column];
			self::configure($view, $settings, []);
			$before = TablesSchemaScene::snapshot();
			$refused = $client->tool('tables_create_column', ['table_id' => $table, 'view_ids' => [$view], 'title' => 'Refused card cleanup', 'type' => 'text', 'subtype' => 'line']);
			Assert::assertFalse($refused->ok(), 'Adding a column must not clear cards of an all-columns view');
			Assert::assertSame($before, TablesSchemaScene::snapshot(), 'Refused column creation writes nothing');
			self::configure($view, $settings, [$column]);
			$before = TablesSchemaScene::snapshot();
			$refused = $client->tool('tables_update_view', ['table_id' => $table, 'view_id' => $view, 'patch' => ['columns' => [$extra]]]);
			Assert::assertFalse($refused->ok(), 'Removing a card source must be refused');
			Assert::assertSame($before, TablesSchemaScene::snapshot(), 'Card-source refusal writes nothing');
			$observed = self::apply($client, 'tables_update_view', ['table_id' => $table, 'view_id' => $view, 'patch' => ['title' => 'Cards preserved']]);
			Assert::assertSame('gallery', $observed['layout']);
			Assert::assertSame($settings, $observed['viewSettings']);
			$fresh = $native->api('GET', "views/$view");
			Assert::assertSame('gallery', $fresh['layout']);
			Assert::assertSame($settings, $fresh['viewSettings']);
		} finally {
			$native->api('DELETE', "columns/$extra");
		}
	}

	/**
	 * @param int $view Disposable native view ID.
	 * @param array<string,int> $settings Existing-column card sources.
	 * @param list<int> $columns Explicit visible selection, or empty for native managed-column fallback.
	 * @return void Configure native 2.4.0 metadata independently of MCP's schema.
	 */
	private static function configure(int $view, array $settings, array $columns): void {
		$credential = LoginFactory::basic(WriteCellScene::OWNER);
		$columnSettings = array_map(static fn (int $id, int $order): array => ['columnId' => $id, 'order' => $order, 'readonly' => false, 'mandatory' => false], $columns, array_keys($columns));
		$reply = Http::request('PUT', Http::BASE . "/index.php/apps/tables/api/1/views/$view",
			['Authorization: ' . $credential->authorization(), 'Accept: application/json', 'Content-Type: application/json', 'OCS-APIRequest: true'],
			json_encode(['data' => ['layout' => 'gallery', 'viewSettings' => $settings, 'columnSettings' => $columnSettings]], JSON_THROW_ON_ERROR));
		Assert::assertSame(200, $reply['status'], 'Native 2.4.0 layout/card setup');
	}

	/**
	 * @param McpClient $client Authenticated schema actor.
	 * @param string $tool Existing schema tool.
	 * @param array<string,mixed> $arguments Explicit requested changes.
	 * @return array<string,mixed> Verified native observation.
	 */
	private static function apply(McpClient $client, string $tool, array $arguments): array {
		$before = TablesSchemaScene::snapshot();
		$plan = $client->tool($tool, $arguments);
		Assert::assertTrue($plan->ok(), $plan->describe());
		Assert::assertTrue($plan->structured()['requiresConfirmation']);
		Assert::assertSame($before, TablesSchemaScene::snapshot());
		$reply = $client->tool($tool, $arguments + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan->structured()['plan_state']]);
		Assert::assertTrue($reply->ok(), $reply->describe());
		Assert::assertSame('observed', $reply->json()['status'], $reply->describe());
		return $reply->json()['observed'];
	}
}
