<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

/**
 * What the send tests read of the throwaway SMTP sink of a Mail cell (ci/mail/smtp-sink.php), which runs inside the
 * Nextcloud container the tests run in and logs one JSON line per SMTP session it received.
 */
trait MailSinkSessions {
	/**
	 * Every session the sink received so far, oldest first.
	 *
	 * @return list<array{at:float, ehlo:string|null, from:string|null, rcpt:list<array{address:string, reply:int}>, data:bool, message:string|null, answered:bool|null, commands:list<string>}> the sessions
	 */
	private function sinkSessions(): array {
		$log = '/tmp/mcp-it-smtp-sink.jsonl';
		clearstatcache(true, $log);
		$lines = is_file($log) ? (array)file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
		return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
	}

	/**
	 * The sessions the sink received after a count taken earlier, waiting briefly for the sink to write the last one.
	 *
	 * @param int $before number of sessions before
	 * @param int $expected number of new sessions expected
	 * @return list<array<string, mixed>> the new sessions
	 */
	private function newSinkSessions(int $before, int $expected): array {
		for ($try = 0; $try < 50 && count($this->sinkSessions()) < $before + $expected; $try++) {
			usleep(100_000);
		}
		return array_slice($this->sinkSessions(), $before);
	}
}
