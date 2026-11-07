<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

/** Loads the versioned baseline-to-current class renames used by the inventory CLI. */
final class InventoryRenames {
	/** Prevents instantiation of this static map reader. */
	private function __construct() {
	}

	/**
	 * Reads explicit class pairs and refuses missing, malformed or conflicting maps.
	 * An empty explicit map remains valid for checking a report with original class names.
	 *
	 * @param string $path default versioned TSV or explicit CLI override
	 * @return array<string,string> baseline class => current class
	 * @throws \RuntimeException when the map cannot be read or contains an invalid pair
	 */
	public static function load(string $path): array {
		if (!is_file($path) || !is_readable($path)) {
			throw new \RuntimeException('Test rename map is missing or unreadable: ' . $path);
		}
		$rows = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($rows === false) {
			throw new \RuntimeException('Cannot read test rename map: ' . $path);
		}
		$map = [];
		foreach ($rows as $line => $row) {
			$pair = explode("\t", $row);
			if (count($pair) !== 2 || trim($pair[0]) === '' || trim($pair[1]) === ''
				|| (isset($map[$pair[0]]) && $map[$pair[0]] !== $pair[1])) {
				throw new \RuntimeException('Invalid test rename pair at ' . $path . ':' . ($line + 1));
			}
			$map[$pair[0]] = $pair[1];
		}
		return $map;
	}
}
