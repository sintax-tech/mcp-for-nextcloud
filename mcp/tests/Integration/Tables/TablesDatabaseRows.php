<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCP\IDBConnection;
use PHPUnit\Framework\Assert;

/** Reads all Tables records through the portable Nextcloud schema and query APIs. */
final class TablesDatabaseRows {
	/**
	 * @param IDBConnection $db Official connection to the disposable cell database.
	 * @param string $prefix Configured physical table prefix, removed before using the query builder.
	 */
	public function __construct(private IDBConnection $db, private string $prefix) {
	}

	/** @return array<string, list<array<string, mixed>>> Every Tables table and row in stable comparison order. */
	public function read(): array {
		$names = [];
		foreach ($this->db->createSchema()->getTables() as $table) {
			$name = $table->getName();
			if (str_starts_with($name, $this->prefix . 'tables_')) {
				$names[] = $name;
			}
		}
		sort($names);
		Assert::assertContains($this->prefix . 'tables_tables', $names, 'the Tables app has its tables');
		$rows = [];
		foreach ($names as $name) {
			Assert::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $name);
			$result = $this->db->getQueryBuilder()->select('*')->from(substr($name, strlen($this->prefix)))->executeQuery();
			try {
				$all = array_map(static function (array $row): array {
					ksort($row);
					return $row;
				}, $result->fetchAll());
			} finally {
				$result->closeCursor();
			}
			usort($all, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
			$rows[$name] = $all;
		}
		return $rows;
	}
}
