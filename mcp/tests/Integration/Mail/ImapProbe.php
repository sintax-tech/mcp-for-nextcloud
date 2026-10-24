<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use RuntimeException;

/**
 * Minimal IMAP4rev1 client of the Mail integration test, independent of the Mail app: it reads the state of every
 * folder of one user straight from the server, in read-only mode (EXAMINE never changes flags, RFC 3501 §6.3.2), so
 * the test can compare that state before and after the MCP calls. CONDSTORE (RFC 7162) gives each folder a
 * HIGHESTMODSEQ that moves on any flag change, which makes the comparison exhaustive.
 */
final class ImapProbe {
	/** Seconds a single read may wait for the server. */
	private const TIMEOUT = 20;

	/** @var resource connection to the server */
	private $socket;

	/** Sequence of the next command tag. */
	private int $tag = 0;

	/**
	 * Connects and logs in.
	 *
	 * @param string $host IMAP server host
	 * @param int $port plain IMAP port
	 * @param string $user IMAP user
	 * @param string $password IMAP password
	 * @return void
	 * @throws RuntimeException when the server cannot be reached or refuses the login
	 */
	public function __construct(string $host, int $port, string $user, string $password) {
		$socket = @stream_socket_client("tcp://$host:$port", $errno, $error, self::TIMEOUT);
		if ($socket === false) {
			throw new RuntimeException("IMAP server unreachable: $error");
		}
		stream_set_timeout($socket, self::TIMEOUT);
		$this->socket = $socket;
		$this->line();
		$this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
	}

	/**
	 * Logs out and closes the connection.
	 *
	 * @return void
	 */
	public function close(): void {
		try {
			$this->command('LOGOUT');
		} catch (RuntimeException) {
			// The server may close the connection right after BYE.
		}
		fclose($this->socket);
	}

	/**
	 * Creates a folder.
	 *
	 * @param string $name folder name, ASCII
	 * @return void
	 * @throws RuntimeException when the server refuses
	 */
	public function create(string $name): void {
		$this->command('CREATE ' . self::quote($name));
	}

	/**
	 * The state of every folder, read without changing anything: attributes, message count, UID validity, next UID,
	 * highest modification sequence and the flags of every message by UID.
	 *
	 * @return array<string, array{attributes:list<string>, exists:int, uidvalidity:string, uidnext:string, highestmodseq:string, flags:array<int, list<string>>}> by folder name, sorted
	 * @throws RuntimeException when the server refuses a command
	 */
	public function state(): array {
		$folders = [];
		foreach ($this->command('LIST "" "*"') as $line) {
			if (preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)"|NIL) (.+)$/', $line, $match) !== 1) {
				continue;
			}
			// \Marked and \Unmarked (RFC 3348) only say whether a folder got messages since it was last selected, and the
			// EXAMINE of this probe moves them: they describe the probe's own sessions, not the folder, so they are left out.
			$attributes = array_values(array_filter(preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [],
				static fn (string $attribute): bool => !in_array(strtolower($attribute), ['\\marked', '\\unmarked'], true)));
			sort($attributes);
			$folders[self::unquote($match[2])] = $attributes;
		}
		ksort($folders);
		$state = [];
		foreach ($folders as $name => $attributes) {
			$state[$name] = ['attributes' => $attributes] + (in_array('\\Noselect', $attributes, true) ? [] : $this->examine($name));
		}
		return $state;
	}

	/**
	 * Opens one folder read-only and reads its counters and flags.
	 *
	 * @param string $name folder name
	 * @return array{exists:int, uidvalidity:string, uidnext:string, highestmodseq:string, flags:array<int, list<string>>} its state
	 * @throws RuntimeException when the server refuses
	 */
	private function examine(string $name): array {
		$folder = ['exists' => 0, 'uidvalidity' => '', 'uidnext' => '', 'highestmodseq' => '', 'flags' => []];
		foreach ($this->command('EXAMINE ' . self::quote($name) . ' (CONDSTORE)') as $line) {
			if (preg_match('/^\* (\d+) EXISTS/', $line, $match) === 1) {
				$folder['exists'] = (int)$match[1];
			}
			foreach (['uidvalidity' => 'UIDVALIDITY', 'uidnext' => 'UIDNEXT', 'highestmodseq' => 'HIGHESTMODSEQ'] as $key => $code) {
				if (preg_match('/\[' . $code . ' (\d+)\]/', $line, $match) === 1) {
					$folder[$key] = $match[1];
				}
			}
		}
		if ($folder['exists'] > 0) {
			foreach ($this->command('FETCH 1:* (UID FLAGS)') as $line) {
				if (preg_match('/UID (\d+)/', $line, $uid) === 1 && preg_match('/FLAGS \(([^)]*)\)/', $line, $flags) === 1) {
					// \Recent belongs to the session that first saw the message, not to the message: it is left out.
					$list = array_values(array_diff(preg_split('/\s+/', trim($flags[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [], ['\\Recent']));
					sort($list);
					$folder['flags'][(int)$uid[1]] = $list;
				}
			}
			ksort($folder['flags']);
		}
		$this->command('CLOSE');
		return $folder;
	}

	/**
	 * Sends one command and reads up to its tagged answer.
	 *
	 * @param string $command command without tag
	 * @return list<string> untagged lines of the answer
	 * @throws RuntimeException when the answer is not OK
	 */
	private function command(string $command): array {
		$tag = 'p' . (++$this->tag);
		fwrite($this->socket, "$tag $command\r\n");
		$lines = [];
		while (true) {
			$line = $this->line();
			if (str_starts_with($line, "$tag ")) {
				if (!str_starts_with($line, "$tag OK")) {
					throw new RuntimeException('IMAP command refused: ' . explode(' ', $command)[0] . ': ' . $line);
				}
				return $lines;
			}
			$lines[] = $line;
		}
	}

	/**
	 * One line from the server, without its line break.
	 *
	 * @return string the line
	 * @throws RuntimeException on timeout or a closed connection
	 */
	private function line(): string {
		$line = fgets($this->socket);
		if ($line === false) {
			throw new RuntimeException('IMAP connection closed or timed out');
		}
		return rtrim($line, "\r\n");
	}

	/**
	 * An IMAP quoted string.
	 *
	 * @param string $value plain value
	 * @return string the quoted value
	 */
	private static function quote(string $value): string {
		return '"' . addcslashes($value, '"\\') . '"';
	}

	/**
	 * A folder name of a LIST line, quoted or atom.
	 *
	 * @param string $value raw name
	 * @return string the name
	 */
	private static function unquote(string $value): string {
		return str_starts_with($value, '"') ? stripcslashes(substr($value, 1, -1)) : $value;
	}
}
