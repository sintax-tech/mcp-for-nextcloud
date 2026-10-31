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
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\TextType;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/** T18 for the B2b tables and the OAuth generation columns. */
final class AuthSchemaTest extends TestCase {
	private const NEW_TABLES = [
		'mcp_auth_users' => ['pk' => ['user_key'], 'text' => ['user_id'], 'columns' => ['user_key', 'user_id', 'issue_seq', 'revoked_below']],
		'mcp_policy_events' => ['pk' => ['id'], 'text' => ['payload'], 'columns' => ['id', 'kind', 'status', 'payload', 'created_at', 'attempts']],
		'mcp_auth_recheck' => ['pk' => ['user_key', 'cause_key'], 'text' => ['user_id', 'group_id'], 'columns' => ['user_key', 'cause_key', 'user_id', 'reason', 'group_id', 'gen', 'created_at']],
		'mcp_group_life' => ['pk' => ['group_key'], 'text' => ['group_id'], 'columns' => ['group_key', 'group_id', 'state', 'gen', 'policy_rev']],
	];

	/** @return list<SimpleMigrationStep> the steps of 1.0.8 */
	private function legacy(): array {
		return [new Version000500Date20260930000000(), new Version000700Date20261001000000(), new Version000800Date20261002000000(),
			new Version000800Date20261002010000(), new Version001002Date20261008000000()];
	}

	/** @return list<SimpleMigrationStep> the steps 1.1.0 adds */
	private function added(): array {
		return [new Version001100Date20261014000000(), new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
			new Version001100Date20261015010000()];
	}

	/** @return array{Schema, \Closure} */
	private function schema(): array {
		$schema = new Schema();
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(fn (string $name): bool => $schema->hasTable($name));
		$wrapper->method('createTable')->willReturnCallback(fn (string $name) => $schema->createTable($name));
		$wrapper->method('getTable')->willReturnCallback(fn (string $name) => $schema->getTable($name));
		return [$schema, fn () => $wrapper];
	}

	/** @return array<string, list<string>> */
	private static function sql(Schema $schema): array {
		$sql = [];
		foreach ([new MySQLPlatform(), new PostgreSQLPlatform(), new SQLitePlatform()] as $platform) {
			$sql[$platform::class] = $schema->toSql($platform);
		}
		return $sql;
	}

	public function testFreshInstallUpgradeAndReexecutionGiveTheSameSchema(): void {
		$output = $this->createMock(IOutput::class);
		[$fresh, $freshClosure] = $this->schema();
		foreach ([...$this->legacy(), ...$this->added()] as $step) {
			$step->changeSchema($output, $freshClosure, []);
		}

		[$upgraded, $closure] = $this->schema();
		foreach ($this->legacy() as $step) {
			$step->changeSchema($output, $closure, []);
		}
		$before = clone $upgraded;
		foreach ($this->added() as $step) {
			$step->changeSchema($output, $closure, []);
		}
		$this->assertSame(self::sql($fresh), self::sql($upgraded));

		// Every 1.0.8 table keeps each column and index; the OAuth tables only gain the two generation columns.
		foreach ($before->getTables() as $old) {
			$new = $upgraded->getTable($old->getName());
			foreach ($old->getColumns() as $column) {
				$this->assertEquals($column, $new->getColumn($column->getName()), $old->getName() . '.' . $column->getName());
			}
			$this->assertEquals($old->getIndexes(), $new->getIndexes(), $old->getName());
			$added = array_diff(array_map(static fn ($c): string => $c->getName(), $new->getColumns()),
				array_map(static fn ($c): string => $c->getName(), $old->getColumns()));
			$expected = in_array($old->getName(), Version001100Date20261015010000::OAUTH_TABLES, true) ? ['auth_global_epoch', 'auth_gen'] : [];
			$this->assertSame($expected, array_values($added), $old->getName());
		}

		$sql = self::sql($upgraded);
		$this->assertNull((new Version001100Date20261015010000())->changeSchema($output, $closure, []));
		$this->assertSame($sql, self::sql($upgraded));
	}

	public function testNewTablesHaveTheContractShapeWithoutIndexOnTextOrForeignKeys(): void {
		[$schema, $closure] = $this->schema();
		foreach ([...$this->legacy(), ...$this->added()] as $step) {
			$step->changeSchema($this->createMock(IOutput::class), $closure, []);
		}
		foreach (self::NEW_TABLES as $name => $shape) {
			$this->assertLessThanOrEqual(24, strlen($name));
			$table = $schema->getTable($name);
			$this->assertSame($shape['pk'], $table->getPrimaryKey()->getColumns(), $name);
			$this->assertSame($shape['columns'], array_map(static fn ($c): string => $c->getName(), $table->getColumns()), $name);
			$this->assertSame([], $table->getForeignKeys(), $name);
			foreach ($shape['text'] as $column) {
				$this->assertInstanceOf(TextType::class, $table->getColumn($column)->getType(), "$name.$column");
			}
			foreach ($table->getIndexes() as $index) {
				$this->assertLessThanOrEqual(24, strlen($index->getName()), $index->getName());
				$this->assertSame([], array_intersect($shape['text'], $index->getColumns()), $index->getName());
			}
			foreach ($table->getColumns() as $column) {
				$this->assertSame($column->getName() !== 'group_id' || $name !== 'mcp_auth_recheck', $column->getNotnull(), "$name.{$column->getName()}");
			}
		}
		foreach (['user_key', 'cause_key'] as $column) {
			$this->assertSame(64, $schema->getTable('mcp_auth_recheck')->getColumn($column)->getLength());
		}
		$this->assertSame(32, $schema->getTable('mcp_auth_recheck')->getColumn('gen')->getLength());
		$this->assertSame(16, $schema->getTable('mcp_auth_recheck')->getColumn('reason')->getLength());
		$this->assertSame(32, $schema->getTable('mcp_group_life')->getColumn('gen')->getLength());
		$this->assertSame(16, $schema->getTable('mcp_group_life')->getColumn('state')->getLength());
		$this->assertSame(24, $schema->getTable('mcp_policy_events')->getColumn('kind')->getLength());
		$index = $schema->getTable('mcp_policy_events')->getIndex('mcp_pol_ev_status');
		$this->assertSame(['status', 'created_at'], $index->getColumns());
		foreach (['issue_seq' => 1, 'revoked_below' => 0] as $column => $default) {
			$this->assertInstanceOf(BigIntType::class, $schema->getTable('mcp_auth_users')->getColumn($column)->getType());
			$this->assertSame($default, $schema->getTable('mcp_auth_users')->getColumn($column)->getDefault());
		}
		foreach (Version001100Date20261015010000::OAUTH_TABLES as $name) {
			$table = $schema->getTable($name);
			foreach (['auth_global_epoch' => 0, 'auth_gen' => 1] as $column => $default) {
				$this->assertInstanceOf(BigIntType::class, $table->getColumn($column)->getType(), "$name.$column");
				$this->assertTrue($table->getColumn($column)->getNotnull());
				$this->assertSame($default, $table->getColumn($column)->getDefault(), "$name.$column");
			}
		}
		foreach (self::sql($schema) as $statements) {
			foreach ($statements as $statement) {
				$this->assertStringNotContainsString('FOREIGN KEY', $statement);
				$this->assertStringNotContainsString('TINYINT', $statement);
			}
		}
	}
}
