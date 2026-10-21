<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Storage;

use InvalidArgumentException;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
class NotesFolders
{
    public const DEFAULT_FOLDER = 'Notes';

    public function __construct(
        private IRootFolder $rootFolder,
        private IConfig $config,
        private ?\OCA\Mcp\Access\Visibility\VisibilityGuard $visibilityGuard = null,
    )
    {
    }

    /**
     * @param string $userId authenticated user
     * @param bool $create create the folder when missing (only for note creation)
     * @return Folder|null the notes folder, null when it does not exist and $create is false
     * @throws InvalidArgumentException when the configured path contains traversal
     */

    public function folder(string $userId, bool $create): ?Folder
    {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path = PathGuard::normalize($this->config->getUserValue($userId, 'notes', 'notesPath', self::DEFAULT_FOLDER) ?: self::DEFAULT_FOLDER);
        if ($create) {
            return NodeAccess::ensureFolder($userFolder, $path, $this->visibilityGuard);
        }
        if (!$userFolder->nodeExists(ltrim($path, '/'))) {
            return null;
        }
        $folder = $userFolder->get(ltrim($path, '/'));
        return $folder instanceof Folder ? $folder : null;
    }

    /**
     * The deepest folder that already exists on the way to a category, which is where a creation lands:
     * the notes folder or a category in it when they exist, otherwise the nearest one that does. The
     * walk stops at anything hidden or not a folder, so what it returns is always something the caller
     * may be told about. Nothing is created.
     *
     * @param string $userId authenticated user
     * @param string $category requested category; '' is the notes folder
     * @return Folder the existing folder the write would reach
     * @throws InvalidArgumentException on traversal or control characters
     */

    public function existingAncestor(string $userId, string $category): Folder
    {
        $folder = $this->rootFolder->getUserFolder($userId);
        $notes = PathGuard::normalize($this->config->getUserValue($userId, 'notes', 'notesPath', self::DEFAULT_FOLDER) ?: self::DEFAULT_FOLDER);
        try {
            $below = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        foreach (PathGuard::segments($notes . '/' . $below) as $segment) {
            if (!$folder->nodeExists($segment)) {
                break;
            }
            $next = $folder->get($segment);
            if (!$next instanceof Folder || $this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($next)) {
                break;
            }
            $folder = $next;
        }
        return $folder;
    }

    /**
     * Walks the category tree as it exists today, without creating anything: the notes root and every
     * ancestor of the requested category that already exists must be visible. A hidden one refuses exactly
     * like a category that does not exist, even for the empty category. The walk stops at the first missing
     * segment, because what is below it would be created under a checked, visible parent
     * ({@see NodeAccess::ensureFolder} with the guard does the same walk when it is time to write).
     *
     * @param Folder $root notes folder
     * @param string $category requested category; '' checks the notes root itself
     * @throws InvalidArgumentException on traversal or control characters
     * @throws ToolFailure when the root or an existing ancestor is hidden
     */

    public function assertCategoryVisible(Folder $root, string $category): void
    {
        try {
            $relative = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        if ($this->visibilityGuard === null) {
            return;
        }
        $this->visibilityGuard->assertVisible($root);
        $folder = $root;
        foreach (PathGuard::segments($relative) as $segment) {
            if (!$folder->nodeExists($segment)) {
                return;
            }
            $next = $folder->get($segment);
            $this->visibilityGuard->assertVisible($next);
            if (!$next instanceof Folder) {
                return;
            }
            $folder = $next;
        }
    }

    /**
     * @param Folder $root notes folder
     * @param string $category subfolder path; '' is the root
     * @return Folder existing or newly created category folder
     * @throws InvalidArgumentException on traversal or control characters
     * @throws ToolFailure when a file already uses one of the folder names
     */

    public function category(Folder $root, string $category): Folder
    {
        try {
            $relative = PathGuard::normalize($category);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Invalid argument: category');
        }
        $folder = NodeAccess::ensureFolder($root, $relative, $this->visibilityGuard);
        if ($this->visibilityGuard !== null) {
            $this->visibilityGuard->assertVisible($folder);
        }
        return $folder;
    }

    /**
     * The category folder as it is now, without creating it.
     *
     * @param Folder|null $root notes folder
     * @param string $category requested category
     * @return Folder|null the folder, or null when it does not exist yet
     * @throws InvalidArgumentException on traversal or control characters
     */

    public function existingCategory(?Folder $root, string $category): ?Folder
    {
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

    public function categoryName(Folder $root, Folder $folder): string
    {
        return trim((string) $root->getRelativePath($folder->getPath()), '/');
    }
}
