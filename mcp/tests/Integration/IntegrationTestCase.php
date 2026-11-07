<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration;

use OC\DB\ConnectionAdapter;
use OC\DB\ConnectionFactory;
use OC\SystemConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base for tests against the real server: the official connection is the one core and the app use; any number of
 * independent sessions to the same database can be opened next to it, built by the core factory from the
 * instance's own configuration.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var list<ConnectionAdapter> */
	private array $independent = [];

	protected function tearDown(): void {
		foreach ($this->independent as $connection) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			$connection->close();
		}
		$this->independent = [];
		$official = $this->official();
		// A failed assertion inside a transaction must not leak it into the next test.
		while ($official->inTransaction()) {
			$official->rollBack();
		}
		parent::tearDown();
	}

	/** @return IDBConnection the server's own connection, shared with core and the app */
	protected function official(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	/**
	 * Opens a separate database session (its own PDO handle) to the database the instance uses: the same SQLite
	 * file, or the same MariaDB/PostgreSQL database. Closed after the test.
	 *
	 * @param (\Closure(\OC\DB\Connection): ConnectionAdapter)|null $adapter builds the adapter around the new
	 *     session, for a test that needs to interleave work at a precise point of a production class's statements
	 * @return ConnectionAdapter an IDBConnection that shares no state with the official connection
	 */
	protected function openIndependentConnection(?\Closure $adapter = null): ConnectionAdapter {
		$config = Server::get(SystemConfig::class);
		$factory = new ConnectionFactory($config);
		$inner = $factory->getConnection((string)$config->getValue('dbtype', 'sqlite'), []);
		$connection = $adapter === null ? new ConnectionAdapter($inner) : $adapter($inner);
		$this->independent[] = $connection;
		// Doctrine connects lazily; connect now so a broken session fails here and not inside an assertion.
		$connection->executeQuery('SELECT 1')->closeCursor();
		return $connection;
	}

	/** @return string 'mariadb', 'pgsql' or 'sqlite', as named by the CI matrix */
	protected function cellDatabase(): string {
		$db = getenv('MCP_IT_DB');
		if (!is_string($db) || $db === '') {
			throw new RuntimeException('MCP_IT_DB is not set; the integration suite runs only through harness.sh');
		}
		return $db;
	}

	/**
	 * Server-side identity of a session: CONNECTION_ID() or pg_backend_pid(); for SQLite, the PHP handle id.
	 *
	 * @param IDBConnection $connection official or independent connection
	 * @return string identifier that differs between independent sessions
	 */
	protected function sessionId(IDBConnection $connection): string {
		$sql = match ($connection->getDatabaseProvider()) {
			IDBConnection::PLATFORM_MYSQL => 'SELECT CONNECTION_ID()',
			IDBConnection::PLATFORM_POSTGRES => 'SELECT pg_backend_pid()',
			default => null,
		};
		if ($sql === null) {
			if (!$connection instanceof ConnectionAdapter) {
				throw new RuntimeException('Unexpected connection class ' . $connection::class);
			}
			return 'php-' . spl_object_id($connection->getInner()->getNativeConnection());
		}
		$result = $connection->executeQuery($sql);
		try {
			return (string)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}
}
