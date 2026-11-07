<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion\Folder;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Modules\Files\Deletion\DeleteNodeAdmission;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** One complete inspection owns its admission context; no approval survives into the next frontier. */
final class DeleteFolderAdmission {
	/**
	 * @param FilesAccessGuard $visibility Fresh resolution through scope, Guests and hidden guards.
	 * @param DeleteNodeAdmission $nodes Common per-node admission without duplicated policy logic.
	 */
	public function __construct(private FilesAccessGuard $visibility, private DeleteNodeAdmission $nodes) {
	}

	/**
	 * Checks external ancestors once and each descendant once while retaining all-provider share coverage.
	 * @param DeleteFolderBoundary $boundary Complete bounded traversal and structural roots gate.
	 * @param Folder $root Actor's personal root.
	 * @param string $uid Acting owner.
	 * @param Folder $source Already resolved source whose identity must still match.
	 * @return array<string,mixed> Complete observation, never a reusable authorization token.
	 */
	public function inspect(DeleteFolderBoundary $boundary, Folder $root, string $uid, Folder $source): array {
		$boundary->assertSource($root, $uid, $source);
		$path = $root->getRelativePath($source->getPath());
		if (!is_string($path)) { throw new ToolFailure(DeleteFolderMessages::refused()); }
		$current = NodeAccess::get($root, $path, $this->visibility);
		if (!$current instanceof Folder || $current->getId() !== $source->getId() || $current->getEtag() !== $source->getEtag()) {
			throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::conflict());
		}
		$shared = $this->nodes->source($root, $uid, $current, $path);
		$observation = $boundary->inspect($root, $uid, $current, function (Node $node) use ($root, $uid, $current, &$shared): void {
			if ($node === $current) { return; }
			$path = $root->getRelativePath($node->getPath());
			if (!is_string($path)) { throw new ToolFailure(DeleteFolderMessages::refused()); }
			$fresh = NodeAccess::get($root, $path, $this->visibility);
			if ($fresh->getId() !== $node->getId() || $fresh->getEtag() !== $node->getEtag()) {
				throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::conflict());
			}
			$impact = $this->nodes->descendant($uid, $fresh, $path);
			$shared = $shared || $impact;
		});
		return $observation + ['shared' => $shared];
	}
}
