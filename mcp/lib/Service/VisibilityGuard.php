<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Enforces privacy of sensitive files and folders tagged with admin-configured system tags.
 *
 * Any node tagged with a hidden tag, any node inside a tagged folder (ancestral inheritance),
 * or any backup of a hidden file is completely invisible to the MCP tools: access is refused
 * as "not found" (CommonMessages::notFound()) to avoid leaking whether the item exists.
 *
 * When no hidden tags are configured in oc_appconfig, all operations have zero overhead.
 */
final class VisibilityGuard {
    public const CONFIG_KEY = 'hidden_system_tags';

    /** @var list<string>|null cached list of hidden system tag IDs */
    private ?array $hiddenTagIds = null;

    /** @var array<int, bool> per-request node visibility cache: [nodeId => isVisible] */
    private array $cache = [];

    public function __construct(
        private IConfig $config,
        private ISystemTagObjectMapper $tagMapper,
        private ?IRootFolder $rootFolder = null,
        private ?ISystemTagManager $tagManager = null,
    ) {}

    /**
     * Checks whether a filesystem node is visible to the MCP caller.
     *
     * @param Node $node file or folder
     * @return bool false when the node or any ancestor has a hidden tag, or is a backup of a hidden file
     */
    public function isVisible(Node $node): bool {
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        $nodeId = (int)$node->getId();
        if ($nodeId > 0 && isset($this->cache[$nodeId])) {
            return $this->cache[$nodeId];
        }

        // Collect ancestors up to root
        $chain = [];
        $current = $node;
        while ($current instanceof Node) {
            $cid = (int)$current->getId();
            if ($cid <= 0 || isset($chain[$cid])) {
                break;
            }

            // If an ancestor is already cached, leverage it
            if (isset($this->cache[$cid])) {
                if (!$this->cache[$cid]) {
                    if ($nodeId > 0) {
                        $this->cache[$nodeId] = false;
                    }
                    return false;
                }
                break;
            }

            $chain[$cid] = $current;
            try {
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === $cid) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                break;
            }
        }

        // Check tags in batch for unverified chain nodes
        if ($chain !== []) {
            $idsToCheck = array_map(static fn (int $id): string => (string)$id, array_keys($chain));
            try {
                $tagsByObject = $this->tagMapper->getTagIdsForObjects($idsToCheck, 'files');
            } catch (\Throwable) {
                $tagsByObject = [];
            }

            // Check from root down to node
            $reversedChain = array_reverse($chain, true);
            $foundHidden = false;
            foreach ($reversedChain as $cid => $chainNode) {
                if ($foundHidden) {
                    $this->cache[$cid] = false;
                    continue;
                }
                $nodeTags = array_map(strval(...), $tagsByObject[(string)$cid] ?? []);
                foreach ($nodeTags as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $foundHidden = true;
                        $this->cache[$cid] = false;
                        break;
                    }
                }
                if (!$foundHidden) {
                    $this->cache[$cid] = true;
                }
            }

            if ($foundHidden) {
                if ($nodeId > 0) {
                    $this->cache[$nodeId] = false;
                }
                return false;
            }
        }

        // Check /MCP backups mirror rule
        if ($this->isHiddenBackup($node, $chain)) {
            if ($nodeId > 0) {
                $this->cache[$nodeId] = false;
            }
            return false;
        }

        if ($nodeId > 0) {
            $this->cache[$nodeId] = true;
        }
        return true;
    }

    /**
     * Asserts that a node is visible, throwing ToolFailure with CommonMessages::notFound() if hidden.
     *
     * @param Node $node
     * @throws ToolFailure
     */
    public function assertVisible(Node $node): void {
        if (!$this->isVisible($node)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
    }

    /**
     * Filters a collection of nodes, keeping only visible ones while preserving order.
     *
     * @param iterable<Node> $nodes
     * @return list<Node>
     */
    public function filter(iterable $nodes): array {
        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node && $this->isVisible($node)) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Checks if a path is allowed for write operations (mkdir, move destination, copy destination).
     * Returns false if an existing target or any existing ancestor is hidden.
     */
    public function isPathAllowedForWrite(Folder $root, string $path): bool {
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        $relative = trim($path, '/');
        if ($relative !== '' && $root->nodeExists($relative)) {
            try {
                $existing = $root->get($relative);
                if (!$this->isVisible($existing)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        // Check existing ancestors
        $segments = array_values(array_filter(explode('/', $relative)));
        while ($segments !== []) {
            array_pop($segments);
            $walk = implode('/', $segments);
            if ($walk === '') {
                break;
            }
            if ($root->nodeExists($walk)) {
                try {
                    $ancestor = $root->get($walk);
                    if (!$this->isVisible($ancestor)) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
                break;
            }
        }

        return true;
    }

    /**
     * Copies any hidden tags from the source node (or its ancestors) to a newly created backup copy.
     */
    public function copyHiddenTags(Node $source, Node $destination): void {
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return;
        }

        $tagsToAssign = [];
        $current = $source;
        while ($current instanceof Node) {
            $cid = (int)$current->getId();
            if ($cid <= 0) {
                break;
            }
            try {
                $tags = $this->tagMapper->getTagIdsForObjects([(string)$cid], 'files');
                foreach ($tags[(string)$cid] ?? [] as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $tagsToAssign[(string)$tagId] = true;
                    }
                }
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === $cid) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                break;
            }
        }

        if ($tagsToAssign !== []) {
            try {
                $this->tagMapper->assignTags((string)$destination->getId(), 'files', array_keys($tagsToAssign));
            } catch (\Throwable) {
                // Non-fatal if tag mapping fails
            }
        }
    }

    /**
     * @return list<string> configured hidden system tag IDs as strings
     */
    public function getHiddenTagIds(): array {
        if ($this->hiddenTagIds !== null) {
            return $this->hiddenTagIds;
        }
        $raw = $this->config->getAppValue('mcp', self::CONFIG_KEY, '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->hiddenTagIds = [];
            return [];
        }
        $this->hiddenTagIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => (string)$id, $decoded),
            static fn (string $id): bool => $id !== '' && $id !== '0'
        )));
        return $this->hiddenTagIds;
    }

    /**
     * Saves the configured hidden system tag IDs.
     *
     * @param list<string|int> $tagIds
     */
    public function setHiddenTagIds(array $tagIds): void {
        $sanitized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => (string)$id, $tagIds),
            static fn (string $id): bool => $id !== '' && $id !== '0'
        )));
        $this->config->setAppValue('mcp', self::CONFIG_KEY, json_encode($sanitized));
        $this->hiddenTagIds = $sanitized;
        $this->cache = [];
    }

    /** Clears the in-memory caches. */
    public function clearCache(): void {
        $this->hiddenTagIds = null;
        $this->cache = [];
    }

    /**
     * Checks if $node is inside the backup folder, and if the original file still exists and is hidden.
     */
    private function isHiddenBackup(Node $node, array $chain): bool {
        // Find ancestor named FileBackup::FOLDER
        $backupAncestor = null;
        $segmentsBelowBackup = [];
        $current = $node;

        while ($current instanceof Node) {
            if ($current->getName() === FileBackup::FOLDER) {
                $backupAncestor = $current;
                break;
            }
            array_unshift($segmentsBelowBackup, $current->getName());
            try {
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === (int)$current->getId()) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                break;
            }
        }

        if ($backupAncestor === null || $segmentsBelowBackup === []) {
            return false;
        }

        try {
            $userFolder = $backupAncestor->getParent();
        } catch (\Throwable) {
            $userFolder = null;
        }

        if (!$userFolder instanceof Folder) {
            return false;
        }

        // Reconstruct original relative path
        $leaf = array_pop($segmentsBelowBackup);
        if ($leaf !== null && preg_match('/^(.+)\.\d{8}-\d{6}(?:-\d+)?\.bak$/', $leaf, $matches)) {
            $leaf = $matches[1];
        }

        $relDir = implode('/', $segmentsBelowBackup);
        $originalRelPath = $relDir === '' ? (string)$leaf : $relDir . '/' . $leaf;

        if ($originalRelPath === '' || !$userFolder->nodeExists($originalRelPath)) {
            return false;
        }

        try {
            $originalNode = $userFolder->get($originalRelPath);
            return !$this->isVisible($originalNode);
        } catch (\Throwable) {
            return false;
        }
    }
}
