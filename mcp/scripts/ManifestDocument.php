<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

/**
 * One manifest file: the ordered list of fragment paths and the problems in the lines themselves.
 *
 * A line is a path relative to `src/`. Blank lines and lines whose first non-space character is `#` are ignored.
 * The order of the remaining lines is the order of the bundle. The same path twice in one manifest is a duplicate;
 * the same path in both manifests is allowed (`shared/`).
 */
final class ManifestDocument {
	/**
	 * Reads a manifest that is already known to be a file.
	 *
	 * @param string $root app directory
	 * @param string $manifest path of the manifest relative to the app directory
	 * @return array{paths: list<string>, problems: list<string>} entries in file order, and line problems
	 */
	public static function read(string $root, string $manifest): array {
		$text = file_get_contents($root . '/' . $manifest);
		if ($text === false) {
			return ['paths' => [], 'problems' => ["missing required manifest: $manifest"]];
		}
		$problems = [];
		$paths = [];
		$seen = [];
		$personal = $manifest === AssetTargets::PERSONAL;
		foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			$outside = AssetTargets::outside($line);
			if ($outside !== null) {
				$problems[] = $outside;
				continue;
			}
			if (isset($seen[$line])) {
				$problems[] = "duplicate entry: $line in $manifest";
				continue;
			}
			$seen[$line] = true;
			if ($personal && str_starts_with($line, AssetTargets::ADMIN_PREFIX)) {
				$problems[] = "admin source in personal manifest: $line";
			}
			$paths[] = $line;
		}
		return ['paths' => $paths, 'problems' => $problems];
	}
}
