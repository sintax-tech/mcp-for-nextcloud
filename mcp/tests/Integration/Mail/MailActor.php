<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use RuntimeException;

/**
 * Runs mail-actor.php in a fresh PHP process as one fixture user, so the Mail services and the MCP registry are
 * built by the real DI for that user only and nothing of another actor stays in memory.
 */
final class MailActor {
	/** Fixture user who owns the account the tools read. */
	public const ALICE = 'mcp-it-alice';

	/** Fixture user whose account alice must never reach. */
	public const BOB = 'mcp-it-bob';

	/**
	 * Runs one action of the actor script.
	 *
	 * @param string $uid fixture user the process acts as
	 * @param string $action action of mail-actor.php
	 * @param array<string, mixed> $input JSON input of the action
	 * @return array<string, mixed> the decoded JSON output
	 * @throws RuntimeException when the process fails or answers no JSON
	 */
	public static function run(string $uid, string $action, array $input = []): array {
		$process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . '/mail-actor.php', $uid, $action],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start the Mail actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]);
		$errors = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		if ($status !== 0) {
			throw new RuntimeException("Mail actor $uid / $action failed ($status): $errors $output");
		}
		try {
			return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RuntimeException("Mail actor $uid / $action returned invalid JSON: $errors $output", 0, $e);
		}
	}
}
