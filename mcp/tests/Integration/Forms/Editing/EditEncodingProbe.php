<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms\Editing;

/** Reads only disposable database encoding metadata; no product SQL, records or credentials are inspected. */
final class EditEncodingProbe {
	/** Verifies the provider's actual database encoding alongside the separate native four-byte round trips.
	 * @return string Observed encoding, safe to log.
	 */
	public static function read():string{
		$db=\OCP\Server::get(\OCP\IDBConnection::class);$mysql=$db->getDatabaseProvider()===\OCP\IDBConnection::PLATFORM_MYSQL;
		$result=$db->executeQuery($mysql?'SELECT @@character_set_database':'SHOW server_encoding');
		try{$value=(string)$result->fetchOne();}finally{$result->closeCursor();}
		if(strtolower($value)!==($mysql?'utf8mb4':'utf8')){throw new \RuntimeException('Disposable database encoding does not support the contracted four-byte boundary');}
		return $value;
	}
}
