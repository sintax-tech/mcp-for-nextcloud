<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * Refuses development-only folders in the app archive: `mcp/scripts/` (package.sh, check_package.php and the
 * restructuring tools in scripts/dev) and `mcp/tests/`.
 *
 * scripts/package.sh already copies a closed list of production folders; this check makes the archive gate say so
 * on its own, so a change to that list can never ship them silently.
 */
final class DevelopmentEntries {
	/** Folders of the app that never ship, as archive paths without trailing slash. */
	public const FORBIDDEN = ['mcp/scripts', 'mcp/tests'];

	/**
	 * Reports every archive entry that is one of the forbidden folders or sits under one, directories included.
	 *
	 * The name is compared as tar extracts it ({@see self::canonical()}), so `mcp/./scripts/x`, `mcp//tests/x` and
	 * `mcp/lib/../scripts/x` are refused like `mcp/scripts/x`.
	 *
	 * @param list<array{0: string, 1: bool}> $entries name and is-directory of each archive entry
	 * @return list<string> one problem per forbidden entry, with the name as written in the archive, sorted by name
	 */
	public static function problems(array $entries): array {
		$names = array_map(static fn (array $entry): string => $entry[0], $entries);
		sort($names, SORT_STRING);
		$found = [];
		foreach ($names as $name) {
			$path = self::canonical($name);
			foreach (self::FORBIDDEN as $folder) {
				if ($path === $folder || str_starts_with($path, $folder . '/')) {
					$found[] = "development-only entry: " . rtrim($name, '/');
				}
			}
		}
		return $found;
	}

	/**
	 * The path an entry name extracts to: empty and `.` segments dropped, `..` applied.
	 *
	 * @param string $name entry name as written in the archive
	 * @return string segments joined by `/`, without leading or trailing slash
	 */
	public static function canonical(string $name): string {
		$segments = [];
		foreach (explode('/', $name) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		return implode('/', $segments);
	}
}
