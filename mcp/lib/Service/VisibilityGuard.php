<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Node;

/**
 * stub até feat/app-hidden-tags
 */
class VisibilityGuard {
    public function isVisible(Node $node): bool {
        return true;
    }

    /**
     * @throws ToolFailure
     */
    public function assertVisible(Node $node): void {
        if (!$this->isVisible($node)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
    }

    /**
     * @param iterable<Node> $nodes
     * @return list<Node>
     */
    public function filter(iterable $nodes): array {
        $visible = [];
        foreach ($nodes as $node) {
            if ($this->isVisible($node)) {
                $visible[] = $node;
            }
        }
        return $visible;
    }
}
