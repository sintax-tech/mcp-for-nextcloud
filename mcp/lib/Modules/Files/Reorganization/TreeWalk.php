<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Walk admitted file trees breadth first within the existing listing limits. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Preserves the existing TreeWalk operations and refusal order. */
final class TreeWalk {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param TreeEntries $treeEntries treeEntries collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly TreeEntries $treeEntries,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Walks a folder breadth-first, one level of `depth` at a time, stopping as soon as `limit` entries
	 * have been collected — a cut walk must not read a whole tree to throw most of it away.
	 *
	 * Directories come before files inside each level and both are alphabetical, so the answer does not
	 * depend on the order the storage happens to return, and so a truncated answer starts with the folders
	 * an agent needs to see to plan.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path folder to walk, user-relative
	 * @param int $depth how many levels below $path to include, 1 meaning only its direct entries
	 * @param int $limit maximum number of entries
	 * @return array{path:string, entries:list<array{name:string, path:string, isDir:bool, size:int, mtime:string, mime:string, access:array<string, mixed>}>, count:int, truncated:bool}
	 * @throws \\InvalidArgumentException for a path with traversal or control characters
	 * @throws ToolFailure when the path is not a readable folder
	 */
	public function tree(Folder $root, string $userId, string $path, int $depth, int $limit): array {
		$path = PathGuard::normalize($path);
		// With a Files scope that does not hold the root, the walk of "/" starts from the selected folders, which are
		// its first level at their real paths; the folders above them are never listed.
		$roots = $path === '/' ? $this->visibilityGuard?->scopeRoots($root) : null;
		if ($roots === null) {
			$start = NodeAccess::get($root, $path, $this->visibilityGuard);
			if (!$start instanceof Folder) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::notAFolder());
			}
		}
		$entries = [];
		$truncated = false;
		// Each level is a list of [node, depth below $path]; folders at the deepest level are not descended.
		$level = $roots === null ? [[$start, 0]] : [[null, 0]];
		for ($current = 0; $current < $depth && $level !== []; $current++) {
			$next = [];
			foreach ($level as [$folder, $levelBelow]) {
				foreach ($folder === null ? $roots : $this->treeEntries->sorted($folder) as $node) {
					if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
						continue;
					}
					// The cut is only a cut once there is one more entry to leave out: a limit equal to the number
					// of entries returns them all, and saying `truncated` then sends the agent after nothing.
					if (count($entries) >= $limit) {
						$truncated = true;
						break 3;
					}
					$relative = $root->getRelativePath($node->getPath()) ?? '';
					$entries[] = $this->treeEntries->entry($root, $userId, $node, $relative);
					if ($node instanceof Folder && $levelBelow + 1 < $depth) {
						$next[] = [$node, $levelBelow + 1];
					}
				}
				if ($truncated) {
					break 2;
				}
			}
			$level = $next;
		}
		return ['path' => $path, 'entries' => $entries, 'count' => count($entries), 'truncated' => $truncated];
	}
}
