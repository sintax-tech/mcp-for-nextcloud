<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Resolve existing ancestors and missing levels without creating folders. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;

/** Preserves the existing FolderLevels operations and refusal order. */
final class FolderLevels {
	/**
	 * The closest folder above $path that already exists, without creating anything on the way.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path folder about to be created
	 * @return Folder the existing ancestor, the user's own root at worst
	 */
	public function existingAncestor(Folder $root, string $path): Folder {
		$segments = PathGuard::segments($path);
		while ($segments !== []) {
			array_pop($segments);
			$walk = '/' . implode('/', $segments);
			if ($root->nodeExists(ltrim($walk, '/'))) {
				$found = $root->get(ltrim($walk, '/'));
				if ($found instanceof Folder) {
					return $found;
				}
			}
		}
		return $root;
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path folder about to be created
	 * @return list<string> user-relative paths of the levels that do not exist yet, shallowest first
	 */
	public function missingLevels(Folder $root, string $path): array {
		$missing = [];
		$walk = '';
		foreach (PathGuard::segments($path) as $segment) {
			$walk .= '/' . $segment;
			if (!$root->nodeExists(ltrim($walk, '/'))) {
				$missing[] = $walk;
			}
		}
		return $missing;
	}
}
