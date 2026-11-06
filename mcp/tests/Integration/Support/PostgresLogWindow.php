<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use Generator;
use RuntimeException;

/** Streams one test's PostgreSQL log tail, retaining only its requested backend and marker window. */
final class PostgresLogWindow {
	private const LINE = '/ mcp-it\[(\d+)\] xid=\d+ ([A-Z]+):\s+(.*)$/';
	private int $offset;

	/** @param string $path Shared cell log, whose current end marks the start of this test's capture. */
	public function __construct(private string $path) {
		$stream = $this->open();
		try {
			if (fseek($stream, 0, SEEK_END) !== 0 || ($offset = ftell($stream)) === false) {
				throw new RuntimeException('Cannot mark PostgreSQL statement log: ' . $path);
			}
			$this->offset = $offset;
		} finally {
			fclose($stream);
		}
	}

	/**
	 * @param int $pid Requested backend session.
	 * @param string $begin Unique first marker.
	 * @param string $end Unique last marker.
	 * @return list<string>|null Statements strictly inside the markers, or null until both have been flushed in order.
	 */
	public function between(int $pid, string $begin, string $end): ?array {
		$started = false;
		$statements = [];
		foreach ($this->entries($pid) as $text) {
			if (!$started) {
				$started = str_contains($text, "'" . $begin . "'");
			} elseif (str_contains($text, "'" . $end . "'")) {
				return $statements;
			} else {
				$statements[] = $text;
			}
		}
		return null;
	}

	/** @return resource Readable log handle; a missing or unreadable log is a harness failure. */
	private function open() {
		$stream = @fopen($this->path, 'rb');
		if ($stream === false) {
			throw new RuntimeException('PostgreSQL statement log not readable: ' . $this->path);
		}
		return $stream;
	}

	/** @param int $pid Requested backend. @return Generator<int, string> Its statements/errors, multiline text intact. */
	private function entries(int $pid): Generator {
		$stream = $this->open();
		try {
			$stat = fstat($stream);
			if ($stat === false || $stat['size'] < $this->offset || fseek($stream, $this->offset) !== 0) {
				throw new RuntimeException('PostgreSQL statement log was truncated or cannot be sought: ' . $this->path);
			}
			$entry = null;
			while (($line = fgets($stream)) !== false) {
				if (!str_ends_with($line, "\n")) {
					break; // The collector has not flushed this entire line yet; the next poll reads it again.
				}
				$line = rtrim($line, "\r\n");
				if (preg_match(self::LINE, $line, $match) === 1) {
					if ($entry !== null) {
						yield $entry;
					}
					$entry = null;
					if ((int)$match[1] !== $pid) {
						continue;
					}
					if ($match[2] === 'LOG' && preg_match('/^(?:statement|execute [^:]+): (.*)$/s', $match[3], $statement) === 1) {
						$entry = $statement[1];
					} elseif ($match[2] === 'ERROR') {
						$entry = 'ERROR: ' . $match[3];
					}
				} elseif ($entry !== null && str_starts_with($line, "\t")) {
					$entry .= "\n" . substr($line, 1);
				}
			}
			if ($entry !== null) {
				yield $entry;
			}
		} finally {
			fclose($stream);
		}
	}
}
