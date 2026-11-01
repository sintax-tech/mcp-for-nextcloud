<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

/** Reads the real manifest units for a former monolithic source assertion. */
final class AdminSources {
	/**
	 * Keep source assertions on the actual loaded files, without rebuilding old closures.
	 *
	 * @param string $path original source path read by the assertion
	 * @return string current source units in manifest order
	 */
	public static function readPath(string $path): string {
		$app = dirname(__DIR__, 3);
		$groups = match (basename($path)) {
			'admin-grants.js' => ['matrix', 'oauth-clients', 'protection', 'checkout', 'logs'],
			'admin-overrides.js' => ['overrides'],
			'admin-groups.js' => ['groups'],
			'admin-scope.js' => ['scope'],
			'admin-inspector.js' => ['inspector'],
			'admin-picker.js' => ['picker'],
			'admin-level-popover.js' => ['level-popover'],
			default => [],
		};
		if ($groups === [] || !is_dir($app . '/src/admin/' . $groups[0])) {
			return (string)file_get_contents($path);
		}
		$units = [];
		foreach (file($app . '/src/admin.manifest', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $entry) {
			foreach ($groups as $group) {
				if (str_starts_with($entry, 'admin/' . $group . '/')) {
					$units[] = (string)file_get_contents($app . '/src/' . $entry);
				}
			}
			if ($entry === 'admin/' . basename($path)) {
				$units[] = (string)file_get_contents($app . '/src/' . $entry);
			}
		}
		return implode(";\n", $units);
	}
	/**
	 * @param list<string> $groups work folders whose strings are asserted together
	 * @return string actual complete scripts in manifest order
	 */
	public static function readGroups(array $groups): string {
		$app = dirname(__DIR__, 3);
		$units = [];
		foreach (file($app . '/src/admin.manifest', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $entry) {
			foreach ($groups as $group) {
				if (str_starts_with($entry, 'admin/' . $group . '/')) {
					$units[] = (string)file_get_contents($app . '/src/' . $entry);
				}
			}
		}
		return implode(";\n", $units);
	}

	/**
	 * @param string $source registered source units
	 * @param string $name declared function name
	 * @return string complete function at its actual indentation
	 */
	public static function operation(string $source, string $name): string {
		$pattern = '/^(\t+)(?:async )?function ' . preg_quote($name, '/') . '\([^\n]*\n.*?^\1}/ms';
		return preg_match($pattern, $source, $match) === 1 ? $match[0] : '';
	}

}
