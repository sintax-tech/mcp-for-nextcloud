<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Inventory;

use RuntimeException;

/** Files inventory follows every logical owner and also scans future physical Modules/Files recursively. */
final class FilesSources {
	/** @param string $root App root. @return list<string> Absolute Files source paths. */
	public static function all(string $root): array {
		$tree = new AppTree($root);
		$paths = [];
		foreach ($tree->classes as $class => $path) {
			if ($tree->owner($class) === 'Modules\\Files') { $paths[$path] = $root . '/' . $path; }
		}
		foreach ($tree->files as $path => $source) {
			if (str_starts_with($path, 'lib/Tools/Files/') || str_starts_with($path, 'lib/Modules/Files/')) { $paths[$path] = $root . '/' . $path; }
		}
		if ($paths === []) { throw new RuntimeException('Files inventory cannot be empty'); }
		foreach ($tree->names as $class => $entry) {
			if ($entry['owner'] === 'Modules\\Files' && isset($tree->classes[$class]) && !isset($paths[$tree->classes[$class]])) { throw new RuntimeException('Mapped Files class escaped inventory: ' . $class); }
		}
		ksort($paths);
		return array_values($paths);
	}

	/** @param string $root App root. @param string $historical Historical sink identity. @return string Present exact sink path. */
	public static function sink(string $root, string $historical): string { return $root . '/' . (new AppTree($root))->present($historical); }
}
