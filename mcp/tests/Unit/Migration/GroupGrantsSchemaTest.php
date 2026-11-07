<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\TextType;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class GroupGrantsSchemaTest extends TestCase {
	public function testFreshInstallAndUpgradeAreAdditiveAndIdempotentOnEachSqlPlatform(): void {
		$finalSql = [];
		foreach ([false, true] as $upgrade) {
			$schema = new Schema();
			$wrapper = $this->createMock(ISchemaWrapper::class);
			$wrapper->method('hasTable')->willReturnCallback(fn (string $name): bool => $schema->hasTable($name));
			$wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
			$output = $this->createMock(IOutput::class);
			$closure = fn () => $wrapper;
			$legacy = [new Version000500Date20260930000000(), new Version000700Date20261001000000(),
				new Version000800Date20261002000000(), new Version000800Date20261002010000(),
				new Version001002Date20261008000000()];
			$migration = new Version001100Date20261014000000();
			if (!$upgrade) {
				// A fresh installation applies the packaged steps, with no existing user data to import.
				foreach ([...$legacy, $migration] as $step) {
					$step->changeSchema($output, $closure, []);
				}
			} else {
				foreach ($legacy as $step) {
					$step->changeSchema($output, $closure, []);
				}
				$before = clone $schema;
				$this->assertFalse($schema->hasTable('mcp_group_grants'));
				$this->assertSame($wrapper, $migration->changeSchema($output, $closure, []));
				foreach ($before->getTables() as $oldTable) {
					$this->assertEquals($oldTable, $schema->getTable($oldTable->getName()), 'Existing tables must not change');
				}
				$this->assertCount(count($before->getTables()) + 1, $schema->getTables());
			}
			$table = $schema->getTable('mcp_group_grants');
			$this->assertSame(['group_key', 'module', 'operation'], $table->getPrimaryKey()->getColumns());
			$this->assertSame(['group_key', 'group_id', 'module', 'operation'],
				array_map(static fn ($column): string => $column->getName(), $table->getColumns()));
			$this->assertSame(64, $table->getColumn('group_key')->getLength());
			$this->assertSame(32, $table->getColumn('module')->getLength());
			$this->assertSame(32, $table->getColumn('operation')->getLength());
			$this->assertInstanceOf(TextType::class, $table->getColumn('group_id')->getType());
			$this->assertSame([], $table->getForeignKeys());
			foreach ($table->getIndexes() as $index) {
				$this->assertNotContains('group_id', $index->getColumns());
				$this->assertLessThanOrEqual(24, strlen($index->getName()));
			}
			foreach ($table->getColumns() as $column) {
				$this->assertTrue($column->getNotnull());
			}
			$sql = [];
			foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
				$name = $platform::class;
				$sql[$name] = $schema->toSql($platform);
				$groupSql = array_values(array_filter($sql[$name], static fn (string $statement): bool => str_contains($statement, 'mcp_group_grants')));
				$this->assertCount(1, $groupSql, 'Only the new table and its inline primary key are needed');
				$this->assertMatchesRegularExpression('/PRIMARY KEY\s*\(group_key, module, operation\)/', $groupSql[0]);
				$this->assertStringNotContainsString('FOREIGN KEY', $groupSql[0]);
				$this->assertStringNotContainsString('group_id)', $groupSql[0]);
			}
			$this->assertNull($migration->changeSchema($output, $closure, []));
			foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
				$this->assertSame($sql[$platform::class], $schema->toSql($platform));
			}
			$finalSql[] = $sql;
		}
		$this->assertSame($finalSql[0], $finalSql[1]);
	}
}
