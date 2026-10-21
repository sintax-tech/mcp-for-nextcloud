<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class DirectoryReader {
    public function __construct(
        private FileEntry $entries,
        private FilesAccessGuard $visibilityGuard,
    ) {}

    /** @return list<array{name:string, path:string, isDir:bool, size:int, mtime:string, contentType:string, access:array<string, mixed>}> */
    public function list(Folder $root, string $userId, string $path): array {
        $listing = \OCA\Mcp\Platform\FileAccess\Paths\PathGuard::normalize($path) === '/' ? $this->visibilityGuard->scopeRoots($root) : null;
        if ($listing === null) {
            $folder = NodeAccess::get($root, $path, $this->visibilityGuard);
            if (!$folder instanceof Folder) {
                throw new ToolFailure(ReadingMessages::notAFolder());
            }
            $listing = $folder->getDirectoryListing();
        }
        $listing = $this->visibilityGuard->filter($listing);
        $entries = array_map(fn (Node $node) => $this->entries->describe($root, $userId, $node), $listing);
        usort($entries, static fn (array $a, array $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);
        return $entries;
    }
}
