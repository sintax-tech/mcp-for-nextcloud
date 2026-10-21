<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class MovePaths {
    public function __construct(
    ) {}

    /**
     * The closest folder above $path that already exists, without creating anything on the way.
     *
     * @param Folder $root the user's folder
     * @param string $path folder about to be created
     * @return Folder the existing ancestor, the user's own root at worst
     */
    public static function existingAncestor(Folder $root, string $path): Folder {
        $segments = PathGuard::segments($path);
        while ($segments !== []) {
            array_pop($segments);
            $walk = '/' . implode('/', $segments);
            if ($root->nodeExists(ltrim($walk, '/'))) {
                $found = $root->get(ltrim($walk, '/'));
                if ($found instanceof Folder) {
                    return $found;
                }
            }
        }
        return $root;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path folder about to be created
     * @return list<string> user-relative paths of the levels that do not exist yet, shallowest first
     */
    public static function missingLevels(Folder $root, string $path): array {
        $missing = [];
        $walk = '';
        foreach (PathGuard::segments($path) as $segment) {
            $walk .= '/' . $segment;
            if (!$root->nodeExists(ltrim($walk, '/'))) {
                $missing[] = $walk;
            }
        }
        return $missing;
    }

    /**
     * The backup folder holds the copies of what MCP overwrote, and they stay hidden only while they sit where the
     * mirror rule expects them: moving, copying or renaming anything in or into it is refused, like an edit is.
     *
     * @param string $path normalized user-relative path, a source or a destination
     * @return string the same path
     * @throws ToolFailure when the path is /MCP backups or inside it
     */
    public static function notBackup(string $path): string {
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
        return $path;
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return string the absolute path in the user's own storage, which is what Node::move() takes
     */
    public static function absolute(Folder $root, string $path): string {
        return $root->getPath() . '/' . ltrim($path, '/');
    }
}
