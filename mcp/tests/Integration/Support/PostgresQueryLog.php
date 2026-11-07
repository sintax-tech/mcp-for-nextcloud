<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCP\IDBConnection;
use RuntimeException;

/**
 * Reads the statement log of the CI PostgreSQL server (log_statement=all, log_line_prefix '%m mcp-it[%p] xid=%x ',
 * see harness.sh). A window is delimited by marker statements sent on the session under test; the statements that
 * session ran between the two markers are what a "no query of the app inside the caller's transaction" proof
 * inspects (contracts §3.3.6, validation T19/F5). An empty window counts only when both markers were captured.
 */
final class PostgresQueryLog {
	private PostgresLogWindow $window;

	/** @param string $path Cell statement log; construction marks the start of this test's capture. */
	public function __construct(private string $path) {
		$this->window = new PostgresLogWindow($path);
	}

	/** @return self|null the log configured for this CI cell, or null when the cell has none */
	public static function fromEnvironment(): ?self {
		$path = getenv('MCP_IT_PG_LOG');
		return is_string($path) && $path !== '' ? new self($path) : null;
	}

	/**
	 * @param IDBConnection $connection a PostgreSQL connection
	 * @return int the backend process id that prefixes this session's log lines
	 */
	public function backendPid(IDBConnection $connection): int {
		$result = $connection->executeQuery('SELECT pg_backend_pid()');
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Sends a uniquely identifiable statement on the session; it is valid inside an open transaction.
	 *
	 * @param IDBConnection $connection session to mark
	 * @param string $label lowercase letters and dashes, for humans reading the log
	 * @return string the marker to pass to statementsBetween()
	 */
	public function mark(IDBConnection $connection, string $label): string {
		if (preg_match('/^[a-z-]+$/', $label) !== 1) {
			throw new RuntimeException('Invalid marker label');
		}
		$marker = 'mcp-it-marker:' . bin2hex(random_bytes(8)) . ':' . $label;
		$connection->executeQuery("SELECT '" . $marker . "' AS mcp_it_marker")->closeCursor();
		return $marker;
	}

	/**
	 * Statements the given backend ran strictly between two of its markers. Waits for the end marker to be flushed
	 * by the logging collector and fails when either marker never shows up, so an empty result is never the
	 * product of a log that captured nothing.
	 *
	 * @param int $pid backend pid of the session under test
	 * @param string $begin marker sent first
	 * @param string $end marker sent last
	 * @param float $timeout seconds to wait for the end marker
	 * @return list<string> statements in log order; errors as 'ERROR: <message>'
	 */
	public function statementsBetween(int $pid, string $begin, string $end, float $timeout = 10.0): array {
		$deadline = microtime(true) + $timeout;
		while (true) {
			$statements = $this->window->between($pid, $begin, $end);
			if ($statements !== null) {
				return $statements;
			}
			if (microtime(true) >= $deadline) {
				throw new RuntimeException('Markers not captured in ' . $this->path . ' for backend ' . $pid);
			}
			usleep(100000);
		}
	}

}
