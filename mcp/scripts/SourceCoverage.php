<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Compares every `src/**\/*.js` file with the paths named by the manifests.
 *
 * A file in `src/` that no manifest names is an orphan. A manifest path that is not a real file under `src/`
 * (missing, or a symlink that resolves outside `src/`) is a missing or escaping entry. Both fail the build.
 */
final class SourceCoverage {
	/**
	 * Every JavaScript file under `src/`, as a path relative to `src/`, sorted by name.
	 *
	 * A missing `src/` directory is an empty tree. Symlinked directories are not followed.
	 *
	 * @param string $root app directory
	 * @return list<string> paths such as `admin/picker.js`
	 */
	public static function javascript(string $root): array {
		$dir = $root . '/src';
		if (!is_dir($dir)) {
			return [];
		}
		$flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME;
		$out = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, $flags)) as $path) {
			if (!is_file($path) || !str_ends_with((string)$path, '.js')) {
				continue;
			}
			$relative = substr((string)$path, strlen($dir) + 1);
			$out[] = str_replace('\\', '/', $relative);
		}
		sort($out, SORT_STRING);
		return $out;
	}

	/**
	 * Orphan files and entries that are not a regular file inside `src/`.
	 *
	 * @param string $root app directory
	 * @param list<string> $covered manifest entries that already passed the line checks, relative to `src/`
	 * @return list<string> problems, unsorted
	 */
	public static function problems(string $root, array $covered): array {
		$problems = [];
		$named = array_fill_keys($covered, true);
		foreach (self::javascript($root) as $file) {
			if (!isset($named[$file])) {
				$problems[] = "orphan source: $file";
			}
		}
		foreach ($covered as $file) {
			$full = $root . '/src/' . $file;
			if (!is_file($full)) {
				$problems[] = "missing entry: $file";
				continue;
			}
			if (!self::inside($root, $file)) {
				$problems[] = "path outside src/: $file";
			}
		}
		return $problems;
	}

	/**
	 * Whether the file resolves to a regular file inside `src/`.
	 *
	 * @param string $root app directory
	 * @param string $file path relative to `src/`
	 * @return bool true when `realpath` stays under `src/`
	 */
	public static function inside(string $root, string $file): bool {
		$src = realpath($root . '/src');
		$resolved = realpath($root . '/src/' . $file);
		if ($src === false || $resolved === false || !is_file($resolved)) {
			return false;
		}
		$prefix = rtrim(str_replace('\\', '/', $src), '/') . '/';
		return str_starts_with(str_replace('\\', '/', $resolved), $prefix);
	}
}
