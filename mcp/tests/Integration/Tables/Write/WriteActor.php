<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

use RuntimeException;

/**
 * Runs write-actor.php in a fresh PHP process for one user of the row write scene, so the Tables services (RowService and
 * the user the container gives it), the session and the MCP registry are built by the real DI for that process only.
 */
final class WriteActor {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Runs one action of the actor script.
	 *
	 * @param string $uid user the tool is called for
	 * @param string $action action of write-actor.php
	 * @param array<string, mixed> $input JSON input of the action
	 * @return array<string, mixed> the decoded JSON output
	 * @throws RuntimeException when the process fails or answers no JSON
	 */
	public static function run(string $uid, string $action, array $input = []): array {
		$process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . '/write-actor.php', $uid, $action],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start the write actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$output = (string)stream_get_contents($pipes[1]);
		$errors = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		if ($status !== 0) {
			throw new RuntimeException("Write actor $uid / $action failed ($status): $errors $output");
		}
		try {
			return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RuntimeException("Write actor $uid / $action returned invalid JSON: $errors $output", 0, $e);
		}
	}

	/**
	 * The plan of a write, then its confirmation with the plan's state, as a client does after the user agreed.
	 *
	 * @param string $uid the caller
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments the arguments
	 * @param array<string, mixed> $options more input of the confirmed call (confirm_shared, failAfterInsert)
	 * @return array{plan: array<string, mixed>, result: array<string, mixed>} the plan and the confirmed answer
	 */
	public static function planAndConfirm(string $uid, string $tool, array $arguments, array $options = []): array {
		$plan = self::run($uid, 'call', ['tool' => $tool, 'arguments' => $arguments]);
		$confirmed = $arguments + ['confirm' => true, 'plan_state' => (string)($plan['structured']['plan_state'] ?? '')]
			+ (($options['confirm_shared'] ?? false) ? ['confirm_shared' => true] : []);
		return ['plan' => $plan, 'result' => self::run($uid, 'call', ['tool' => $tool, 'arguments' => $confirmed,
			'failAfterInsert' => (bool)($options['failAfterInsert'] ?? false)])];
	}
}
