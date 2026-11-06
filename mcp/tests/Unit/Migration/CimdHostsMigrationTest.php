<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\{MySQLPlatform, PostgreSQLPlatform, SQLitePlatform};
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Migration\{Version000500Date20260930000000, Version001205Date20261106000000};
use OCA\Mcp\Tests\Unit\OAuth\ClientsTable;
use OCP\DB\ISchemaWrapper;
use OCP\{IAppConfig, IConfig, IDBConnection};
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** The packaged migration is additive, portable, repeatable and preserves explicit host settings and tombstones. */
final class CimdHostsMigrationTest extends TestCase {
	/** @return void Fresh and pre-existing OAuth tables both receive the same schema, including legacy stamp defaults. */
	public function testSchemaIsPortableAndIdempotent(): void {
		$schema = new Schema();
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
		$wrapper->method('createTable')->willReturnCallback(fn ($name) => $schema->createTable($name));
		$wrapper->method('getTable')->willReturnCallback(fn ($name) => $schema->getTable($name));
		$out = $this->createMock(IOutput::class);
		(new Version000500Date20260930000000())->changeSchema($out, fn () => $wrapper, []);
		$migration = new Version001205Date20261106000000($this->createMock(IDBConnection::class), $this->createMock(IConfig::class), $this->createMock(IAppConfig::class));
		self::assertSame($wrapper, $migration->changeSchema($out, fn () => $wrapper, []));
		self::assertNull($migration->changeSchema($out, fn () => $wrapper, []));
		foreach (['mcp_oauth_codes', 'mcp_oauth_tokens'] as $table) {
			self::assertSame(1, $schema->getTable($table)->getColumn('client_gen')->getDefault());
		}
		foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
			self::assertNotEmpty($schema->toSql($platform));
		}
	}

	/** @return void Explicit hosts are imported as generation 1; reruns do not re-enable tombstones or rewrite config. */
	public function testExplicitAllowlistIsImportedOnceWithoutRevivingWithdrawnHosts(): void {
		$table = new ClientsTable($this);
		$table->db->dbal->executeStatement('DELETE FROM mcp_cimd_hosts');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn(' Dedicated.Example , chatgpt.com ');
		$config->expects(self::never())->method('setAppValue');
		$migration = new Version001205Date20261106000000($table->db->connection(), $config, $this->createMock(IAppConfig::class));
		$out = $this->createMock(IOutput::class);
		$migration->postSchemaChange($out, fn () => null, []);
		self::assertNull($table->mapper->cimdHosts()->find('claude.ai'));
		self::assertSame('1', $table->mapper->cimdHosts()->find('dedicated.example')['gen']);
		$table->mapper->cimdHosts()->put('dedicated.example', false, '9');
		$migration->postSchemaChange($out, fn () => null, []);
		self::assertSame(['host' => 'dedicated.example', 'enabled' => false, 'gen' => '9'], $table->mapper->cimdHosts()->find('dedicated.example'));
	}
}
