<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

final class FolderPreparation {
    public function __construct(
        private FilesAccessGuard $visibilityGuard,
    ) {}

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
     */
    public function checkFolder(Folder $root, string $path): void {
        $relative = ltrim(MovePaths::notBackup(PathGuard::normalize($path)), '/');
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
                    throw new ToolFailure(FileAccessMessages::forbidden());
                }
                return;
            }
            $existing = $root->get($below);
            // An existing level is crossed by metadata: excluded ones refuse, ancestors of a selected folder pass.
            if (!$this->visibilityGuard->isTraversable($existing)) {
                // Excluded destinations always use the same generic resource refusal.
                throw new ToolFailure(FileAccessMessages::notFound());
            }
            if (!$existing instanceof Folder) {
                // A file in the way is only named when the user may reach it: otherwise "it exists" would tell a
                // file outside the Files scope from a missing path (D6). A file is never an ancestor of a selected
                // folder, so this costs no traversal.
                $this->visibilityGuard->assertVisible($existing);
                throw new ToolFailure(ReorganizationMessages::destinationExists());
            }
            $parent = $existing;
        }
        $this->assertBoundary($parent);
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
            $this->checkFolder($root, $path);
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
                        throw new ToolFailure(ReorganizationMessages::destinationExists());
                    }
                    continue;
                }
                $folder = NodeAccess::run(fn () => NodeAccess::mutate(fn () => $root->newFolder($below), $this->visibilityGuard));
                $this->visibilityGuard->assertVisible($folder);
                $created[] = '/' . $below;
            }
        }
        return $created;
    }

    private function assertBoundary(Folder $folder): void {
        $this->visibilityGuard->assertVisible($folder);
    }
}
