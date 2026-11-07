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
		return (new EditDatabaseEncoding(\OCP\Server::get(\OCP\IDBConnection::class)))->read();
	}
}
