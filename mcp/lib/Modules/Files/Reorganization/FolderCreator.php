<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

final class FolderCreator {
    public function __construct(
        private NodeAccessInfo $access,
        private SharedWriteGuard $guard,
        private MoveInspection $moveInspection,
        private FilesAccessGuard $visibilityGuard,
    ) {}

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
            throw new ToolFailure(ReorganizationMessages::destinationExists());
        }
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        $relPath = ltrim($path, '/');
        if ($root->nodeExists($relPath)) {
            try {
                $existing = $root->get($relPath);
                if (!$this->visibilityGuard->isVisible($existing)) {
                    throw new ToolFailure(FileAccessMessages::notFound());
                }
            } catch (ToolFailure $e) {
                throw $e;
            } catch (\Throwable) {
                throw new ToolFailure(FileAccessMessages::notFound());
            }
            throw new ToolFailure(ReorganizationMessages::destinationExists());
        }
        $parent = MovePaths::existingAncestor($root, $path);
        $this->visibilityGuard->assertVisible($parent);
        if (($payload = $this->guard->guard($parent, $userId, $path, $confirmedShared)) !== null) {
            return ['path' => $path, 'created' => false, 'created_paths' => []] + $payload;
        }
        if (!$parent->isCreatable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        $created = MovePaths::missingLevels($root, $path);
        $folder = NodeAccess::ensureFolder($root, $path, $this->visibilityGuard);
        $this->visibilityGuard->assertVisible($folder);
        return ['path' => $path, 'created' => true, 'created_paths' => $created];
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
            throw new ToolFailure(ReorganizationMessages::destinationExists());
        }
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        $rel = ltrim($path, '/');
        if ($root->nodeExists($rel)) {
            try {
                $existing = $root->get($rel);
                if (!$this->visibilityGuard->isVisible($existing)) {
                    throw new ToolFailure(FileAccessMessages::notFound());
                }
            } catch (ToolFailure $e) {
                throw $e;
            } catch (\Throwable) {
                throw new ToolFailure(FileAccessMessages::notFound());
            }
            throw new ToolFailure(ReorganizationMessages::destinationExists());
        }
        $parent = MovePaths::existingAncestor($root, $path);
        $this->visibilityGuard->assertVisible($parent);
        if (!$parent->isCreatable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        return [
            'action' => 'files_mkdir',
            'path' => $path,
            'created' => MovePaths::missingLevels($root, $path),
            'createdIn' => $root->getRelativePath($parent->getPath()) ?? '',
            'access' => $this->access->describe($parent, $userId),
            'shared' => $this->moveInspection->sharedOf($userId, $parent),
            'recoverable' => true,
            'message' => ReorganizationMessages::planMkdir(),
        ];
    }
}
