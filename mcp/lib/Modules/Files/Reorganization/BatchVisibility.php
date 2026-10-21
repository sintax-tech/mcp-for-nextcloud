<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

final class BatchVisibility {
    public function __construct(
        private FilesAccessGuard $visibilityGuard,
    ) {}

    /**
     * Refuses the whole undo when anything it would touch is hidden by a tag, directly or through a folder above it.
     *
     * What it touches: each item where the batch left it, the place it goes back to (the item already there, or the
     * nearest folder that exists above it) and each folder the batch created. The tags may have been set after the
     * batch ran, so the batch record proves nothing. The refusal names no path and no item.
     *
     * @param Folder $root the user's folder
     * @param Batch $batch the batch to undo
     * @throws ToolFailure when one of those nodes is hidden or cannot be checked
     */
    public function assertUndoVisible(Folder $root, Batch $batch): void {
        $paths = $batch->dirs;
        foreach ($batch->moves as $move) {
            $paths[] = $move['to'];
            $paths[] = $move['from'];
        }
        $this->assertPathsVisible($root, $paths);
    }

    /** @param list<string> $paths paths or nearest existing ancestors to check */
    public function assertPathsVisible(Folder $root, array $paths): void {
        if ($this->visibilityGuard === null) {
            return;
        }
        foreach ($paths as $path) {
            try {
                $segments = PathGuard::segments(PathGuard::normalize($path, 'moves'));
                // The node itself when it exists, otherwise the nearest folder above it that does, the user's
                // folder itself at the top.
                while ($segments !== [] && !$root->nodeExists(implode('/', $segments))) {
                    array_pop($segments);
                }
                $visible = $this->visibilityGuard->isVisible($segments === [] ? $root : $root->get(implode('/', $segments)));
            } catch (\Throwable) {
                $visible = false;
            }
            if (!$visible) {
                throw new ToolFailure(ReorganizationMessages::batchHasHiddenItem());
            }
        }
    }
}
