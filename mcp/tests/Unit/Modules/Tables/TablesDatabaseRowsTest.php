<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Tests\Integration\Tables\TablesDatabaseRows;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/** Checks complete snapshots, prefix isolation and refusal without a Tables installation. */
final class TablesDatabaseRowsTest extends TestCase {
	/** @return void Metadata discovery and prefix handling use only the common OCP schema/query surfaces. */
	public function testPortableSchemaDiscoveryPreservesAllRowsAndEmptyTables(): void {
		$schema = new Schema();
		foreach (['it_tables_tables', 'it_tables_rows', 'it_tables_empty', 'it_forms_forms', 'itxtables_rows', 'other_tables_rows'] as $name) {
			$schema->createTable($name);
		}
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())->method('createSchema')->willReturn($schema);
		$db->expects(self::never())->method('getDatabaseProvider');
		$db->expects(self::never())->method('executeQuery');
		$seen = [];
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$seen): IQueryBuilder {
			$query = $this->createMock(IQueryBuilder::class);
			$query->expects(self::once())->method('select')->with('*')->willReturnSelf();
			$table = '';
			$query->method('from')->willReturnCallback(static function (string $name) use (&$table, &$seen, $query): IQueryBuilder {
				$table = $name;
				$seen[] = $name;
				return $query;
			});
			$query->method('executeQuery')->willReturnCallback(function () use (&$table): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(match ($table) {
					'tables_empty' => [],
					'tables_rows' => [['value' => 'two', 'id' => 2], ['value' => 'one', 'id' => 1]],
					'tables_tables' => [['title' => 'Table', 'id' => 7]],
				});
				$result->expects(self::once())->method('closeCursor');
				return $result;
			});
			return $query;
		});
		self::assertSame([
			'it_tables_empty' => [],
			'it_tables_rows' => [['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']],
			'it_tables_tables' => [['id' => 7, 'title' => 'Table']],
		], (new TablesDatabaseRows($db, 'it_'))->read());
		self::assertSame(['tables_empty', 'tables_rows', 'tables_tables'], $seen, 'Only the configured prefix is removed for OCP');
	}

	/** @return void Real SQLite schema discovery and reads produce stable snapshots that detect changed content. */
	public function testRealSqliteSnapshotDetectsMutations(): void {
		$database = new SqliteDatabase($this, []);
		$database->dbal->executeStatement('CREATE TABLE tables_tables (id INTEGER PRIMARY KEY, title TEXT)');
		$database->dbal->executeStatement('CREATE TABLE tables_rows (id INTEGER PRIMARY KEY, value TEXT)');
		$database->dbal->executeStatement('CREATE TABLE unrelated (id INTEGER)');
		$database->dbal->insert('tables_tables', ['id' => 1, 'title' => 'Synthetic']);
		$database->dbal->insert('tables_rows', ['id' => 2, 'value' => '😀']);
		$database->dbal->insert('tables_rows', ['id' => 1, 'value' => 'first']);
		$db = $database->connection();
		$db->method('createSchema')->willReturnCallback(static fn (): Schema => $database->dbal->createSchemaManager()->introspectSchema());
		$snapshot = new TablesDatabaseRows($db, '');
		$before = $snapshot->read();
		self::assertSame(['tables_rows', 'tables_tables'], array_keys($before));
		self::assertSame([1, 2], array_column($before['tables_rows'], 'id'));
		self::assertSame('😀', $before['tables_rows'][1]['value']);
		self::assertSame($before, $snapshot->read());
		$database->dbal->update('tables_rows', ['value' => 'changed'], ['id' => 2]);
		self::assertNotSame($before, $snapshot->read());
	}

	/** @return void A missing Tables installation must never become an empty passing snapshot. */
	public function testMissingTablesStillFails(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('createSchema')->willReturn(new Schema());
		$db->expects(self::never())->method('getQueryBuilder');
		$this->expectException(AssertionFailedError::class);
		(new TablesDatabaseRows($db, 'oc_'))->read();
	}
}
