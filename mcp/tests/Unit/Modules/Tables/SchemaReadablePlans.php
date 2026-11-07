<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Modules\Tables\TablesToolCatalog;
use OCA\Mcp\Modules\Tables\Integration\{SchemaGateway, TablesWriteCapabilities};
use OCA\Mcp\Modules\Tables\Writing\SchemaFlow;
use OCA\Mcp\Modules\Tables\TablesSessionGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use PHPUnit\Framework\TestCase;

/** Real protocol-generated plans added to the unchanged readability assertions. */
final class SchemaReadablePlans {
	/**
	 * @param TestCase $test Running test.
	 * @return array<string,array<string,mixed>> Plans for each additional Tables write.
	 */
	public static function of(TestCase $test): array {
		$mock = new \ReflectionMethod($test, 'createMock');
		$config = (new InMemoryConfig())->mock($test);
		$grants = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		foreach (['schema', 'share'] as $grant) $grants->setGrant('alice', 'tables', $grant, true);
		$gateway = $mock->invoke($test, SchemaGateway::class);
		$gateway->method('prepare')->willReturnCallback(static fn ($action, $args): array => ['arguments' => $args, 'profile' => '2.3.2', 'version' => '2.3.2', 'before' => [], 'after' => ['title' => 'new'], 'shared' => false, 'noop' => false]);
		$caps = SchemaCapabilityFixture::of($mock->invoke($test, \OCP\App\IAppManager::class));
		$flow = new SchemaFlow($gateway, new PlanState($config), $grants, $mock->invoke($test, TablesSessionGuard::class), $caps, new \OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry([$caps], $mock->invoke($test, \Psr\Log\LoggerInterface::class)));
		$plans = [];
		foreach (array_slice(TablesToolCatalog::TOOLS, 6) as $class) {
			$name = $class::definition()->name;
			$action = substr($name, 7);
			$args = match ($action) {
				'create_table' => ['title' => 'new'],
				'update_table' => ['table_id' => 1, 'patch' => ['title' => 'new']],
				'create_column' => ['table_id' => 1, 'title' => 'new', 'type' => 'text', 'subtype' => 'line'],
				'update_column' => ['table_id' => 1, 'column_id' => 2, 'patch' => ['title' => 'new']],
				'create_view' => ['table_id' => 1, 'title' => 'new'],
				'update_view' => ['table_id' => 1, 'view_id' => 2, 'patch' => ['title' => 'new']],
				'share_node' => ['node_type' => 'table', 'node_id' => 1, 'recipient' => ['type' => 'user', 'id' => 'bob'], 'permissions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'manage' => false]],
				'unshare_node' => ['node_type' => 'view', 'node_id' => 1, 'share_id' => 2],
			};
			$plans[$name] = $flow->preview(SchemaActionFixture::of($test, $action), $args, 'alice');
		}
		return $plans;
	}
}
