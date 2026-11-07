<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Enforce reachability and visibility at folder creation boundaries. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Preserves the existing FolderBoundary operations and refusal order. */
final class FolderBoundary {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation backupLocation collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Runs on one folder of a batch every check files_mkdir runs, without creating anything.
	 *
	 * The plan calls it for each folder so it never approves one the run would refuse, and the run calls it for
	 * all of them again before the first folder is made.
	 *
	 * @param Folder $root the user's folder
	 * @param string $path requested folder, user-relative
	 * @throws \InvalidArgumentException for a malformed path
	 * @throws ToolFailure when the folder is in the backup folder, a file already uses one of its names, it is or
	 *   sits under a hidden node, or the folder that would receive it cannot take a new entry
	 * @return void
	 */
	public function checkFolder(Folder $root, string $path): void {
		$relative = ltrim(MoveInspection::notBackup(PathGuard::normalize($path), $this->backupLocation), '/');
		$parent = $root;
		$walk = [];
		foreach (PathGuard::segments($relative) as $segment) {
			$walk[] = $segment;
			$below = implode('/', $walk);
			if (!$root->nodeExists($below)) {
				// The first missing level is created in the last one that exists, so that one has to be reachable,
				// inside the Files scope (the boundary of F5), and take it.
				$this->assertBoundary($parent);
				if (!$parent->isCreatable()) {
					throw new ToolFailure(CommonMessages::forbidden());
				}
				return;
			}
			$existing = $root->get($below);
			// An existing level is crossed by metadata: excluded ones refuse, ancestors of a selected folder pass.
			if ($this->visibilityGuard !== null && !$this->visibilityGuard->isTraversable($existing)) {
				// Excluded destinations always use the same generic resource refusal.
				throw new ToolFailure(CommonMessages::notFound());
			}
			if (!$existing instanceof Folder) {
				// A file in the way is only named when the user may reach it: otherwise "it exists" would tell a
				// file outside the Files scope from a missing path (D6). A file is never an ancestor of a selected
				// folder, so this costs no traversal.
				$this->assertReachable($existing);
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
			}
			$parent = $existing;
		}
		$this->assertBoundary($parent);
	}

	/**
	 * @param Folder $folder the deepest existing folder of a destination
	 * @throws ToolFailure not found when it is hidden or outside the Files scope
	 * @return void
	 */
	private function assertBoundary(Folder $folder): void {
		$this->assertReachable($folder);
	}

	/**
	 * @param Node $node an existing node of a destination path, about to be named in a refusal or written below
	 * @throws ToolFailure not found when it is hidden or outside the Files scope
	 * @return void
	 */
	public function assertReachable(Node $node): void {
		if ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}
}
