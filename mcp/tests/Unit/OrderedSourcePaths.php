<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

/** Sorts source path expectations independently of their declaration order during moves. */
final class OrderedSourcePaths {
	/** Prevents instantiation of the assertion support. */
	private function __construct() {
	}

	/**
	 * Sorts a flat list or a method-to-paths map without dropping any entry.
	 *
	 * @param array<array-key,string|list<string>> $paths source paths or grouped paths
	 * @return array<array-key,string|list<string>> sorted expectation
	 */
	public static function sorted(array $paths): array {
		foreach ($paths as &$value) {
			if (is_array($value)) {
				sort($value);
			}
		}
		unset($value);
		if (array_is_list($paths)) {
			sort($paths);
		} else {
			ksort($paths);
		}
		return $paths;
	}
}
