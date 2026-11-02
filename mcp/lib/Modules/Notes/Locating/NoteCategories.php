<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Locating;

use InvalidArgumentException;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;

/**
 * The category folders of the notes folder as they are now: a plan and a search look them up without creating
 * any, and a hidden one answers exactly like one that does not exist.
 */
final class NoteCategories {
	/**
	 * @param VisibilityGuard|null $visibilityGuard administrator exclusions, null when none apply
	 */
	public function __construct(
		private ?VisibilityGuard $visibilityGuard,
	) {
	}

	/**
	 * The category folder as it is now, without creating it.
	 *
	 * @param Folder|null $root notes folder
	 * @param string $category requested category
	 * @return Folder|null the folder, or null when it does not exist yet
	 * @throws InvalidArgumentException on traversal or control characters
	 */
	public function existing(?Folder $root, string $category): ?Folder {
		// Even the notes root goes through the guard: an empty category means writing at the root itself.
		if ($root !== null && $this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($root)) {
			return null;
		}
		if ($root === null || trim($category, '/') === '') {
			return $root;
		}
		try {
			$relative = ltrim(PathGuard::normalize($category, 'category'), '/');
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException('Invalid argument: category');
		}
		$found = null;
		if ($root->nodeExists($relative)) {
			$node = $root->get($relative);
			if ($this->visibilityGuard === null || $this->visibilityGuard->isVisible($node)) {
				$found = $node;
			}
		}
		return $found instanceof Folder ? $found : null;
	}

	/**
	 * @param Folder $root notes folder
	 * @param Folder $folder category folder below it
	 * @return string the category as the other tools name it
	 */
	public function name(Folder $root, Folder $folder): string {
		return trim((string)$root->getRelativePath($folder->getPath()), '/');
	}
}
