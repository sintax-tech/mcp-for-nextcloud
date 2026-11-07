<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Create admitted folders only after the existing shared and boundary checks. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Backups\FileBackup;

/** Preserves the existing FolderCreator operations and refusal order. */
final class FolderCreator {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param FolderLevels $folderLevels folderLevels collaborator.
	 * @param SharedWriteGuard $guard guard collaborator.
	 * @param FolderBoundary $folderBoundary folderBoundary collaborator.
	 * @param ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation backupLocation collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly FolderLevels $folderLevels,
		private readonly SharedWriteGuard $guard,
		private readonly FolderBoundary $folderBoundary,
		private readonly ?\OCA\Mcp\Platform\Backups\BackupLocation $backupLocation = null,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Creates one folder, and whatever parents are missing, refusing to touch a destination that is taken.
	 *
	 * The refusal is deliberate rather than a silent reuse: the point of the reorganization is to place
	 * files in a tree the user described, and a folder that already holds something else would swallow
	 * them without anyone deciding that.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path folder to create, user-relative
	 * @param bool $confirmedShared whether the caller passed confirm_shared
	 * @return array{path:string, created:bool, created_paths:list<string>}
	 * @throws \InvalidArgumentException for a path with traversal or control characters
	 * @throws ToolFailure when the destination is taken, inside the backup folder, denied, or shared without confirmation
	 */
	public function mkdir(Folder $root, string $userId, string $path, bool $confirmedShared): array {
		$path = PathGuard::normalize($path);
		if ($path === '/') {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
		}
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath());
		}
		$relPath = ltrim($path, '/');
		if ($root->nodeExists($relPath)) {
			if ($this->visibilityGuard !== null) {
				try {
					$existing = $root->get($relPath);
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
		if (($payload = $this->guard->guard($parent, $userId, $path, $confirmedShared)) !== null) {
			return ['path' => $path, 'created' => false, 'created_paths' => []] + $payload;
		}
		if (!$parent->isCreatable()) {
			throw new ToolFailure(CommonMessages::forbidden());
		}
		$created = $this->folderLevels->missingLevels($root, $path);
		$folder = NodeAccess::ensureFolder($root, $path, $this->visibilityGuard);
		$this->visibilityGuard?->assertVisible($folder);
		return ['path' => $path, 'created' => true, 'created_paths' => $created];
	}

	/**
	 * The shared-content confirmation a folder creation needs, the one `files_mkdir` asks for.
	 *
	 * The folder is judged by the closest folder that already exists above it: that is where it is made, and
	 * so whose people it reaches. A folder that is already there is not made, so it asks for nothing. The
	 * batch plan calls this for each folder of `mkdirs`, so a folder no move uses is guarded like the rest.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param string $path requested folder, user-relative
	 * @return array<string, mixed>|null the guard payload, null when the folder is not made in a shared place
	 * @throws \InvalidArgumentException for a malformed path
	 */
	public function guardFolder(Folder $root, string $userId, string $path): ?array {
		$path = PathGuard::normalize($path);
		$relative = ltrim($path, '/');
		if ($relative === '' || $root->nodeExists($relative)) {
			return null;
		}
		return $this->guard->guard($this->folderLevels->existingAncestor($root, $path), $userId, $path, false);
	}

	/**
	 * Creates the folders a batch asked for and answers which ones it really made.
	 *
	 * Every folder created counts, including a parent created on the way, because the undo removes what the
	 * batch made and a folder it only implied is still one it made.
	 *
	 * @param Folder $root the user's folder
	 * @param list<string> $paths requested folders
	 * @return list<string> the created folders, shallowest first
	 * @throws \InvalidArgumentException for a malformed path
	 * @throws ToolFailure when {@see self::checkFolder()} refuses one of them, or the folder cannot be created
	 */
	public function createFolders(Folder $root, array $paths): array {
		// Every folder is checked before the first one is made: stopping halfway would leave folders that no
		// recorded batch knows about, and so that no undo can remove.
		foreach ($paths as $path) {
			$this->folderBoundary->checkFolder($root, $path);
		}
		$created = [];
		foreach ($paths as $path) {
			$relative = ltrim(PathGuard::normalize($path), '/');
			if ($relative === '') {
				continue;
			}
			$walk = [];
			foreach (PathGuard::segments($relative) as $segment) {
				$walk[] = $segment;
				$below = implode('/', $walk);
				if ($root->nodeExists($below)) {
					$existing = $root->get($below);
					if (!$existing instanceof Folder) {
						$this->folderBoundary->assertReachable($existing);
						throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FilesMessages::destinationExists());
					}
					continue;
				}
				$folder = NodeAccess::run(fn () => NodeAccess::mutate(fn () => $root->newFolder($below), $this->visibilityGuard));
				$this->visibilityGuard?->assertVisible($folder);
				$created[] = '/' . $below;
			}
		}
		return $created;
	}
}
