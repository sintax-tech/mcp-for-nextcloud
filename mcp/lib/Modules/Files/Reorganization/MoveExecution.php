<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Files\Node;

final class MoveExecution {
    public function __construct(
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * Runs a move: checks the locks of the node, and of everything inside a folder, right before it, and explains a
     * lock refusal of the storage.
     *
     * @param callable():Node $write the move
     * @param Node $node the node it moves
     * @param string $userId authenticated user
     * @return Node what the write returned
     */
    public function locked(callable $write, Node $node, string $userId): Node {
        return NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $node, $userId), $this->visibilityGuard);
    }
}
