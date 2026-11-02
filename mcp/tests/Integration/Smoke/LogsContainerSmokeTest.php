<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Smoke;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;

/**
 * The Logs tools are published by CatalogModule and built from the real container on the first call.
 * A unit test never asks that container for ListLogs.
 */
final class LogsContainerSmokeTest extends IntegrationTestCase {
	private const ADMIN = 'admin';

	public function testTheContainerListsAndReadsOneLogPage(): void {
		$policy = Server::get(GrantPolicy::class);
		$policy->setGrant(self::ADMIN, 'logs', 'read', true);
		try {
			$registry = Server::get(ToolRegistry::class);
			$names = array_column($registry->list(self::ADMIN), 'name');
			$this->assertContains('logs_list', $names);
			$this->assertContains('logs_analyze', $names);

			$result = $registry->call('logs_list', ['limit' => 1], self::ADMIN);
		} finally {
			$policy->setGrant(self::ADMIN, 'logs', 'read', false);
		}

		$this->assertArrayNotHasKey('isError', $result);
		$this->assertStringContainsString('UNTRUSTED LOG DATA', $result['content'][0]['text']);
		$this->assertTrue($result['structuredContent']['untrusted_log_data']);
		$this->assertSame('logs_list', $result['structuredContent']['tool']);
		$this->assertLessThanOrEqual(1, $result['structuredContent']['count']);
		$this->assertCount($result['structuredContent']['count'], $result['structuredContent']['entries']);
	}
}
