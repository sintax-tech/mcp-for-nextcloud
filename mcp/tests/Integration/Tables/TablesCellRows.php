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
		return (new TablesDatabaseRows(Server::get(IDBConnection::class),
			Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_')))->read();
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
