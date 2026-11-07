<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms\Editing;

use OCP\IDBConnection;
use RuntimeException;

/** Verifies each provider's encoding metadata without sending another provider's SQL to it. */
final class EditDatabaseEncoding {
	/** @param IDBConnection $db Official connection to the disposable cell database. */
	public function __construct(private IDBConnection $db) {
	}

	/** @return string Actual encoding, alongside the separate native four-byte round-trip assertions. */
	public function read(): string {
		[$sql, $allowed] = match ($this->db->getDatabaseProvider()) {
			IDBConnection::PLATFORM_MYSQL => ['SELECT @@character_set_database', ['utf8mb4']],
			IDBConnection::PLATFORM_POSTGRES => ['SHOW server_encoding', ['utf8']],
			IDBConnection::PLATFORM_SQLITE => ['PRAGMA encoding', ['utf-8', 'utf-16le', 'utf-16be']],
			default => throw new RuntimeException('Unsupported disposable database provider for encoding proof'),
		};
		$result = $this->db->executeQuery($sql);
		try {
			$value = (string)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
		if (!in_array(strtolower($value), $allowed, true)) {
			throw new RuntimeException('Disposable database encoding does not support the contracted four-byte boundary');
		}
		return $value;
	}
}
