<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Resolve free receiving paths and their admitted existing ancestors. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Preserves the existing MoveDestination operations and refusal order. */
final class MoveDestination {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $to destination path, user-relative
	 * @throws ToolFailure when anything already sits there
	 * @return void
	 */
	public function assertFree(Folder $root, string $to): void {
		$rel = ltrim($to, '/');
		if ($to === '/' || $root->nodeExists($rel)) {
			if ($to !== '/' && $this->visibilityGuard !== null) {
				try {
					$existing = $root->get($rel);
					if (!$this->visibilityGuard->isVisible($existing)) {
						throw new ToolFailure(CommonMessages::notFound());
					}
				} catch (ToolFailure $e) {
					throw $e;
				} catch (\Throwable) {
					throw new ToolFailure(CommonMessages::notFound());
				}
			}
			throw new MoveConflict(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
		}
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path destination path, user-relative
	 * @param list<string> $mkdirs planned folders whose existing ancestor supplies permissions and storage
	 * @return Folder the destination folder, or the existing ancestor of a planned folder
	 * @throws ToolFailure when it does not exist or cannot take new entries
	 */
	public function destination(Folder $root, string $path, array $mkdirs = []): Folder {
		$segments = PathGuard::segments($path);
		array_pop($segments);
		$parentPath = '/' . implode('/', $segments);
		if ($parentPath !== '/' && !$root->nodeExists(ltrim($parentPath, '/'))) {
			$planned = false;
			foreach ($mkdirs as $dir) {
				$dir = PathGuard::normalize($dir);
				if ($dir === $parentPath || str_starts_with($dir, $parentPath . '/')) {
					$planned = true;
					break;
				}
			}
			if ($planned) {
				// Only mkdirs and their implied parents exist in the plan. Check the real ancestor:
				// a missing descendant must never bypass visibility, permissions or storage boundaries.
				do {
					array_pop($segments);
					$parentPath = '/' . implode('/', $segments);
				} while ($parentPath !== '/' && !$root->nodeExists(ltrim($parentPath, '/')));
			}
		}
		$parent = NodeAccess::run(fn () => NodeAccess::get($root, $parentPath, $this->visibilityGuard));
		if (!$parent instanceof Folder || !$parent->isCreatable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		return $parent;
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path user-relative path
	 * @return string the absolute path in the user's own storage, which is what Node::move() takes
	 */
	public function absolute(Folder $root, string $path): string {
		return $root->getPath() . '/' . ltrim($path, '/');
	}
}
