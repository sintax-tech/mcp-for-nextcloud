<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

/** Canonical object-key order for strict native value/type comparisons; no values are cast or recomputed. */
final class PollsNativeEquality {
	/**
	 * Sorts keys recursively while preserving every native scalar type, value and whitelist field.
	 * @param array<mixed> $value native or MCP projection
	 * @return array<mixed> key-canonical value
	 */
	public static function keys(array $value): array {
		foreach ($value as &$item) {
			if (is_array($item)) {
				$item = self::keys($item);
			}
		}
		unset($item);
		ksort($value);
		return $value;
	}
	/**
	 * CLI bootstrap includes /index.php while this Apache cell uses pretty URLs; compare that known routing difference
	 * only. Exact full URLs are separately compared against native getURL in the identical bootstrap context.
	 * @param string $url synthetic native or HTTP URL, never modified in production
	 * @return string path with only the leading front controller removed for this fixture comparison
	 */
	public static function routingPath(string $url): string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path)) {
			throw new \RuntimeException('Missing fixture URL path');
		}
		return str_starts_with($path, '/index.php/') ? substr($path, strlen('/index.php')) : $path;
	}

}
