<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class TreeReader {
    public const MAX_DEPTH = 5;
    public const MAX_ENTRIES = 2000;

    public function __construct(
        private NodeAccessInfo $access,
        private FilesAccessGuard $visibilityGuard,
    ) {}

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
        $roots = $path === '/' ? $this->visibilityGuard->scopeRoots($root) : null;
        if ($roots === null) {
            $start = NodeAccess::get($root, $path, $this->visibilityGuard);
            if (!$start instanceof Folder) {
                throw new ToolFailure(ReadingMessages::notAFolder());
            }
        }
        $entries = [];
        $truncated = false;
        // Each level is a list of [node, depth below $path]; folders at the deepest level are not descended.
        $level = $roots === null ? [[$start, 0]] : [[null, 0]];
        for ($current = 0; $current < $depth && $level !== []; $current++) {
            $next = [];
            foreach ($level as [$folder, $levelBelow]) {
                foreach ($folder === null ? $roots : TreeEntries::sorted($folder) as $node) {
                    if (!$this->visibilityGuard->isVisible($node)) {
                        continue;
                    }
                    // The cut is only a cut once there is one more entry to leave out: a limit equal to the number
                    // of entries returns them all, and saying `truncated` then sends the agent after nothing.
                    if (count($entries) >= $limit) {
                        $truncated = true;
                        break 3;
                    }
                    $relative = $root->getRelativePath($node->getPath()) ?? '';
                    $entries[] = $this->entry($root, $userId, $node, $relative);
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

    /**
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param Node $node entry to describe
     * @param string $relative user-relative path of the entry
     * @return array{name:string, path:string, isDir:bool, size:int, mtime:string, mime:string, access:array<string, mixed>}
     */
    private function entry(Folder $root, string $userId, Node $node, string $relative): array {
        $isDir = NodeAccess::isFolder($node);
        return [
            'name' => $node->getName(),
            'path' => $relative,
            'isDir' => $isDir,
            'size' => $isDir ? 0 : (int)$node->getSize(),
            'mtime' => gmdate('D, d M Y H:i:s \\G\\M\\T', (int)$node->getMTime()),
            'mime' => $isDir ? 'httpd/unix-directory' : (string)$node->getMimetype(),
            'access' => $this->access->describe($node, $userId),
        ];
    }
}
