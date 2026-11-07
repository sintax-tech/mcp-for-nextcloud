<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BigIntType;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class PolicyStateSchemaTest extends TestCase {
	public function testAdditiveStateSchemaIsPortableAndReentrant(): void {
		$schema = new Schema();
		$schema->createTable('mcp_existing')->addColumn('id', 'integer');
		$before = clone $schema->getTable('mcp_existing');
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(fn ($name) => $schema->hasTable($name));
		$wrapper->method('createTable')->willReturnCallback(fn ($name) => $schema->createTable($name));
		$step = new Version001100Date20261015000000($this->createMock(IDBConnection::class));
		$output = $this->createMock(IOutput::class);
		$this->assertSame($wrapper, $step->changeSchema($output, fn () => $wrapper, []));
		$this->assertEquals($before, $schema->getTable('mcp_existing'));
		$table = $schema->getTable('mcp_policy_state');
		$this->assertSame(['id'], $table->getPrimaryKey()->getColumns());
		$this->assertSame([], $table->getForeignKeys());
		foreach (['revision', 'lock_seq', 'auth_epoch'] as $column) {
			$this->assertInstanceOf(BigIntType::class, $table->getColumn($column)->getType());
			$this->assertTrue($table->getColumn($column)->getNotnull());
			$this->assertSame(0, $table->getColumn($column)->getDefault());
		}
		$snapshot = clone $schema;
		$this->assertNull($step->changeSchema($output, fn () => $wrapper, []));
		foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform(), new OraclePlatform()] as $platform) {
			$sql = $schema->toSql($platform);
			$this->assertSame($snapshot->toSql($platform), $sql);
			$this->assertCount(2, $sql);
			$this->assertStringContainsString('mcp_policy_state', $sql[1]);
			$this->assertStringNotContainsString('TINYINT', $sql[1]);
		}
	}
}
