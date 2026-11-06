<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Describe folder creation without changing the native tree. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FileBackup;

/** Preserves the existing FolderPlan operations and refusal order. */
final class FolderPlan {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param FolderLevels $folderLevels folderLevels collaborator.
	 * @param NodeAccessInfo $access access collaborator.
	 * @param MoveReceipt $moveReceipt moveReceipt collaborator.
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation backupLocation collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly FolderLevels $folderLevels,
		private readonly NodeAccessInfo $access,
		private readonly MoveReceipt $moveReceipt,
		private readonly ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * The plan of `files_mkdir`: the levels that do not exist yet, and the folder they would be created in.
	 *
	 * Every check of the write runs here first, so a plan never describes a folder the tool would refuse.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path folder that would be created, user-relative
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException for a path with traversal or control characters
	 * @throws ToolFailure when the destination is taken, inside the backup folder or denied
	 */
	public function planMkdir(Folder $root, string $userId, string $path): array {
		$path = PathGuard::normalize($path);
		if ($path === '/') {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
		}
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath());
		}
		$rel = ltrim($path, '/');
		if ($root->nodeExists($rel)) {
			if ($this->visibilityGuard !== null) {
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
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
		}
		$parent = $this->folderLevels->existingAncestor($root, $path);
		if ($this->visibilityGuard !== null) {
			$this->visibilityGuard->assertVisible($parent);
		}
		if (!$parent->isCreatable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		return [
			'action' => 'files_mkdir',
			'path' => $path,
			'created' => $this->folderLevels->missingLevels($root, $path),
			'createdIn' => $root->getRelativePath($parent->getPath()) ?? '',
			'access' => $this->access->describe($parent, $userId),
			'shared' => $this->moveReceipt->sharedOf($userId, $parent),
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::planMkdir(),
		];
	}
}
