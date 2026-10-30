<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Isolated native comparison/fixture actor, executed only inside the official Nextcloud cell. */
final class PollsContentActor {
	/**
	 * Calls the focused actor without OC_CONSOLE and without logging returned credential URLs.
	 * @param string $uid fixture actor
	 * @param string $action native/observe/prepare/configure
	 * @param array<string,mixed> $input fixture request
	 * @return array<string,mixed> pure observations
	 */
	public static function run(string $uid, string $action, array $input = []): array {
		$process = proc_open([PHP_BINARY, __DIR__ . '/polls-content-actor.php', $uid, $action],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot start fixture actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		if ($code !== 0) {
			throw new \RuntimeException('Fixture actor failed (' . $code . '): ' . $err);
		}
		return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
	}
}
