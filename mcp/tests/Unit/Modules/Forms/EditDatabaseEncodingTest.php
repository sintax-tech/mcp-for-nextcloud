<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Tests\Integration\Forms\Editing\EditDatabaseEncoding;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Encoding proof queries are provider-specific and never replace the native Forms round-trip assertions. */
final class EditDatabaseEncodingTest extends TestCase {
	/** @return iterable<string, array{string, string, string, bool}> Correct and incompatible metadata per provider. */
	public static function encodings(): iterable {
		yield 'mysql-mariadb' => [IDBConnection::PLATFORM_MYSQL, 'SELECT @@character_set_database', 'utf8mb4', true];
		yield 'mysql-three-byte' => [IDBConnection::PLATFORM_MYSQL, 'SELECT @@character_set_database', 'utf8', false];
		yield 'postgres' => [IDBConnection::PLATFORM_POSTGRES, 'SHOW server_encoding', 'UTF8', true];
		yield 'postgres-incompatible' => [IDBConnection::PLATFORM_POSTGRES, 'SHOW server_encoding', 'LATIN1', false];
		yield 'sqlite-utf8' => [IDBConnection::PLATFORM_SQLITE, 'PRAGMA encoding', 'UTF-8', true];
		yield 'sqlite-utf16le' => [IDBConnection::PLATFORM_SQLITE, 'PRAGMA encoding', 'UTF-16le', true];
		yield 'sqlite-utf16be' => [IDBConnection::PLATFORM_SQLITE, 'PRAGMA encoding', 'UTF-16be', true];
		yield 'sqlite-missing' => [IDBConnection::PLATFORM_SQLITE, 'PRAGMA encoding', '', false];
	}

	/**
	 * @param string $provider Official OCP provider identifier.
	 * @param string $sql Only applicable metadata query.
	 * @param string $encoding Observed metadata.
	 * @param bool $compatible Whether the provider supports the contracted Unicode boundary.
	 * @return void No other provider's statement is executed; incompatible metadata fails closed.
	 */
	#[DataProvider('encodings')]
	public function testProviderMetadata(string $provider, string $sql, string $encoding, bool $compatible): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($encoding);
		$result->expects(self::once())->method('closeCursor');
		$db->expects(self::once())->method('executeQuery')->with($sql)->willReturn($result);
		if (!$compatible) {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('does not support the contracted four-byte boundary');
		}
		self::assertSame($encoding, (new EditDatabaseEncoding($db))->read());
	}

	/** @return void An unknown provider cannot accidentally take the PostgreSQL branch or be accepted. */
	public function testUnknownProviderFailsBeforeSql(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn('unknown');
		$db->expects(self::never())->method('executeQuery');
		$this->expectException(RuntimeException::class);
		(new EditDatabaseEncoding($db))->read();
	}

	/** @return iterable<string, array{string}> Every documented SQLite storage encoding supports full Unicode. */
	public static function sqliteEncodings(): iterable {
		foreach (['UTF-8', 'UTF-16le', 'UTF-16be'] as $encoding) {
			yield $encoding => [$encoding];
		}
	}

	/**
	 * @param string $encoding Actual SQLite storage encoding configured before creating a table.
	 * @return void Real metadata and four-byte text round-trip agree without SHOW or a skipped assertion.
	 */
	#[DataProvider('sqliteEncodings')]
	public function testRealSqliteMetadataAndFourByteRoundTrip(string $encoding): void {
		$pdo = new PDO('sqlite::memory:');
		$pdo->exec("PRAGMA encoding = '$encoding'");
		$pdo->exec('CREATE TABLE unicode_probe (value TEXT)');
		$value = str_repeat('😀', 1024);
		$insert = $pdo->prepare('INSERT INTO unicode_probe (value) VALUES (?)');
		$insert->execute([$value]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$db->expects(self::once())->method('executeQuery')->with('PRAGMA encoding')->willReturnCallback(function () use ($pdo): IResult {
			$statement = $pdo->query('PRAGMA encoding');
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturnCallback(static fn (): mixed => $statement->fetchColumn());
			$result->expects(self::once())->method('closeCursor')->willReturnCallback(static function () use ($statement): bool {
				return $statement->closeCursor();
			});
			return $result;
		});
		self::assertSame($encoding, (new EditDatabaseEncoding($db))->read());
		self::assertSame($value, $pdo->query('SELECT value FROM unicode_probe')->fetchColumn());
	}
}
