<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;

/**
 * What the Tables integration tests read of the database of a Tables cell: every row of every table of the Tables app,
 * to prove a call changed nothing, and the tables a user owns, to prove no tutorial table was created.
 */
trait TablesCellRows {
	/**
	 * Every row of every database table of the Tables app, by table, in a stable order.
	 *
	 * @return array<string, list<array<string, mixed>>> rows by database table
	 */
	private function tablesAppRows(): array {
		$db = Server::get(IDBConnection::class);
		$prefix = (string)Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$schema = $db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES ? 'current_schema()' : 'DATABASE()';
		$names = $db->executeQuery("SELECT table_name FROM information_schema.tables WHERE table_schema = $schema AND table_name LIKE ?",
			[str_replace('_', '\\_', $prefix . 'tables_') . '%'])->fetchAll(\PDO::FETCH_COLUMN);
		sort($names);
		$this->assertContains($prefix . 'tables_tables', $names, 'the Tables app has its tables');
		$rows = [];
		foreach ($names as $name) {
			// Names straight from the catalog, checked to be plain identifiers before they go into the statement.
			$this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
			$result = $db->executeQuery('SELECT * FROM ' . $name);
			$all = array_map(static function (array $row): array {
				ksort($row);
				return $row;
			}, $result->fetchAll());
			$result->closeCursor();
			usort($all, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
			$rows[$name] = $all;
		}
		return $rows;
	}

	/**
	 * Ids of the tables a user owns.
	 *
	 * @param string $uid the user
	 * @return list<int> table ids
	 */
	private function ownedTables(string $uid): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$result = $qb->select('id')->from('tables_tables')->where($qb->expr()->eq('ownership', $qb->createNamedParameter($uid)))->orderBy('id')->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * The JSON answer of a successful call.
	 *
	 * @param array<string, mixed> $result MCP result
	 * @return array<string, mixed> the answer
	 */
	private function decoded(array $result): array {
		$this->assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
		$this->assertArrayNotHasKey('refused', $result);
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
