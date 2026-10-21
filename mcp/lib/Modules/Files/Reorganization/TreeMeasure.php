<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Files\Reading\TreeEntries;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

final class TreeMeasure {
    public function __construct(
        private FilesAccessGuard $visibilityGuard,
    ) {}

    /**
     * Counts the nodes and bytes a copy would bring, stopping as soon as a ceiling is passed so a huge
     * tree is not measured to the end just to be refused.
     *
     * A hidden node inside the tree stops the copy too: every copied node gets a new id, the hidden tag belongs
     * to the id of the original, and the copy of a hidden file would be readable by anyone who can read the
     * folder it lands in. The answer is the generic denial, so the count of what is hidden is never told.
     *
     * @param Node $node the node a copy would start from, a file or a whole folder
     * @return array{nodes:int, bytes:int}
     * @throws ToolFailure when the source is over either ceiling or holds a hidden node
     */
    public function measure(Node $node): array {
        $nodes = $bytes = 0;
        $pending = [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
            if ($current !== $node && !$this->visibilityGuard->isVisible($current)) {
                throw new ToolFailure(FileAccessMessages::forbidden());
            }
            $nodes++;
            if ($nodes > ReorganizationLimits::NODES) {
                throw new ToolFailure(ReorganizationMessages::copyTooManyNodes(ReorganizationLimits::NODES));
            }
            if ($current instanceof Folder) {
                foreach (TreeEntries::sorted($current) as $child) {
                    $pending[] = $child;
                }
                continue;
            }
            $bytes += (int)$current->getSize();
            if ($bytes > ReorganizationLimits::BYTES) {
                throw new ToolFailure(ReorganizationMessages::copyTooLarge(ReorganizationLimits::BYTES));
            }
        }
        return ['nodes' => $nodes, 'bytes' => $bytes];
    }
}
