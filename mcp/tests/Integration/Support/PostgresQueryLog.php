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
	private const LINE = '/ mcp-it\[(\d+)\] xid=\d+ ([A-Z]+):\s+(.*)$/';

	public function __construct(private string $path) {}

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
			$entries = array_values(array_filter($this->entries(), static fn (array $e): bool => $e['pid'] === $pid));
			$from = $this->indexOf($entries, $begin);
			$to = $this->indexOf($entries, $end);
			if ($from !== null && $to !== null && $to > $from) {
				return array_map(static fn (array $e): string => $e['text'], array_slice($entries, $from + 1, $to - $from - 1));
			}
			if (microtime(true) >= $deadline) {
				throw new RuntimeException('Markers not captured in ' . $this->path . ' for backend ' . $pid);
			}
			usleep(100000);
		}
	}

	/**
	 * @param list<array{pid: int, text: string}> $entries
	 * @param string $marker marker text
	 * @return int|null index of the statement carrying the marker
	 */
	private function indexOf(array $entries, string $marker): ?int {
		foreach ($entries as $index => $entry) {
			if (str_contains($entry['text'], "'" . $marker . "'")) {
				return $index;
			}
		}
		return null;
	}

	/** @return list<array{pid: int, text: string}> statements and errors of all sessions, in log order */
	private function entries(): array {
		$lines = @file($this->path, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			throw new RuntimeException('PostgreSQL statement log not readable: ' . $this->path);
		}
		$entries = [];
		$last = null;
		foreach ($lines as $line) {
			if (preg_match(self::LINE, $line, $m) === 1) {
				$last = null;
				$text = $m[3];
				if ($m[2] === 'LOG' && preg_match('/^(?:statement|execute [^:]+): (.*)$/s', $text, $statement) === 1) {
					$text = $statement[1];
				} elseif ($m[2] === 'ERROR') {
					$text = 'ERROR: ' . $text;
				} else {
					// DETAIL (bound parameters), STATEMENT (repeats a failed statement) and server messages.
					continue;
				}
				$entries[] = ['pid' => (int)$m[1], 'text' => $text];
				$last = array_key_last($entries);
			} elseif ($last !== null && str_starts_with($line, "\t")) {
				// Continuation line of a multi-line statement.
				$entries[$last]['text'] .= "\n" . substr($line, 1);
			}
		}
		return $entries;
	}
}
