<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Access;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** D7 uses metadata only. Callers check node visibility first (D6), then this gate, then recursive helpers. */
final class FolderExposureGate {
    public static function active(VisibilityGuard $guard): bool {
        return (!$guard->isConfigurationValid() || $guard->getHiddenTagIds() !== []);
    }

    public static function assertExposure(Node $node, VisibilityGuard $guard): void {
        if (NodeAccess::isFolder($node) && self::active($guard)) {
            throw new ToolFailure(FolderExposureMessages::folderExposureRefused());
        }
    }

    /** A folder rename is allowed only when both actual parents have the same positive canonical file id. */
    public static function assertMove(Folder $root, Node $source, string $to, VisibilityGuard $guard): void {
        if (!NodeAccess::isFolder($source) || !self::active($guard)) {
            return;
        }
        try {
            $parentPath = dirname(PathGuard::normalize($to));
            $targetParent = $parentPath === '/' ? $root : $root->get(ltrim($parentPath, '/'));
            // Above a mount root core lends the parent another storage's id; the guard resolves the real one.
            $sourceParent = $guard->parentOf($source);
            $sameParent = $targetParent instanceof Folder && $sourceParent instanceof Folder
                && (int)$sourceParent->getId() > 0
                && (int)$sourceParent->getId() === (int)$targetParent->getId();
        } catch (\Throwable) {
            $sameParent = false;
        }
        if (!$sameParent) {
            throw new ToolFailure(FolderExposureMessages::folderExposureRefused());
        }
    }
    /**
     * Classify the whole batch before recursive checks or mutation.
     * @param list<array{from:string, to:string}> $moves
     */
    public static function assertMoves(Folder $root, array $moves, VisibilityGuard $guard, bool $undo = false): void {
        if (!$guard->isConfigurationValid()) {
            throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\FileAccessMessages::notFound());
        }
        if (!FolderExposureGate::active($guard)) {
            return;
        }
        foreach ($moves as $item) {
            $from = $undo ? $item['to'] : $item['from'];
            $to = $undo ? $item['from'] : $item['to'];
            try {
                $source = NodeAccess::run(fn (): Node => NodeAccess::get($root, $from, $guard));
            } catch (ToolFailure) {
                // Existing planning/undo checks retain their normal missing/hidden/ACL refusals.
                continue;
            }
            FolderExposureGate::assertMove($root, $source, $to, $guard);
        }
    }
}
