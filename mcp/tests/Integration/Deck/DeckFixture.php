<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Deck;

use RuntimeException;

/** Native fixtures only; each actor boots a fresh CLI request, so Deck DI never retains another actor's UID. */
final class DeckFixture {
	public const OWNER = 'mcp-it-deck-owner';
	public const READER = 'mcp-it-deck-reader';
	public const EDITOR = 'mcp-it-deck-editor';
	public const OUTSIDER = 'mcp-it-deck-outsider';
	public const GROUP = 'mcp-it-deck-members';

	public static function run(string $uid, string $action, array $input = []): array {
		$process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . '/actor.php', $uid, $action],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start Deck fixture actor');
		}
		fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		if ($status !== 0) {
			throw new RuntimeException("Deck fixture $uid / $action failed ($status): $errors $output");
		}
		try {
			return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new RuntimeException("Deck fixture $uid / $action returned invalid JSON: $errors $output", 0, $e);
		}
	}
}
