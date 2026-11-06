<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Migration\Version001205Date20261106000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** Adds the production CIMD schema/defaults to the existing focused clients fixture. */
final class CimdTableSeed {
	/** @param TestCase $test Test owning doubles. @param SqliteDatabase $db Clients fixture. @return void */
	public function apply(TestCase $test, SqliteDatabase $db): void {
		$mock = fn (string $type) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $type);
		$config = $mock(IConfig::class);
		$config->method('getAppValue')->willReturn('claude.ai,chatgpt.com');
		$migration = new Version001205Date20261106000000($db->connection(), $config, $mock(IAppConfig::class));
		$schema = new \Doctrine\DBAL\Schema\Schema();
		$wrapper = $mock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(fn (string $name) => $schema->hasTable($name));
		$wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
		$output = $mock(IOutput::class);
		$migration->changeSchema($output, fn () => $wrapper, []);
		foreach ($schema->toSql($db->dbal->getDatabasePlatform()) as $sql) {
			$db->dbal->executeStatement($sql);
		}
		$migration->postSchemaChange($output, fn () => $wrapper, []);
	}
}
