<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/**
 * Finds the writes in native Collectives source: file and folder creation, content and rename on nodes, the page and
 * index builders, database inserts, updates and deletes, raw statements and stored settings. Used on the Collectives
 * bodies a read chain reaches (collectives-read-chains.json); what leaves Collectives is checked against the explicit
 * allowlist collectives-external-leaves.json instead.
 */
final class NativeWrites {
	/** Every native call that writes, as it appears in source. */
	public const PATTERN = '/\b(newFolder|newFile|putContent|newPage|createIndexPage|initSubFolder|updateOrInsert|insertOrUpdate'
		. '|executeStatement|setUserValue|setAppValue|setValue|touch|rename)\s*\(|->(insert|update|delete|save|move|copy)\s*\(/';

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param array<string, string> $methods method bodies by `Class::method`
	 * @return array<string, list<string>> the writes found, by method; empty when the methods only read
	 */
	public static function in(array $methods): array {
		$found = [];
		foreach ($methods as $method => $body) {
			if (preg_match_all(self::PATTERN, $body, $m)) {
				$found[$method] = array_values(array_unique(array_map(static fn (string $call): string => trim($call, "-> \t("), $m[0])));
			}
		}
		ksort($found);
		return $found;
	}

}
