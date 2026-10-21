<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Enforces privacy of sensitive files and folders tagged with admin-configured system tags.
 *
 * Any node tagged with a hidden tag, any node inside a tagged folder (ancestral inheritance),
 * or any backup of a hidden file is completely invisible to the MCP tools: access is refused
 * as "not found" (FileAccessMessages::notFound()) to avoid leaking whether the item exists.
 *
 * When no hidden tags are configured in oc_appconfig, all operations have zero overhead.
 */
class VisibilityGuard {
    public const CONFIG_KEY = 'hidden_system_tags';

    /** Reasons explain() gives; only the administrator's inspector shows them, the tools answer "not found". */
    public const EXPLAINED_VISIBLE = 'visible';
    public const EXPLAINED_TAG_DIRECT = 'tag_direct';
    public const EXPLAINED_TAG_FOLDER = 'tag_folder';
    public const EXPLAINED_TAG_SHARE_ORIGIN = 'tag_share_origin';
    public const EXPLAINED_BACKUP = 'backup_of_excluded';
    public const EXPLAINED_INVALID_CONFIGURATION = 'invalid_configuration';
    /** The check failed (a tag, an ancestor or the owner's tree could not be read): refused, reason unknown. */
    public const EXPLAINED_NOT_EVALUATED = 'not_evaluated';

    /** @var list<string>|null cached list of hidden system tag IDs */
    private ?array $hiddenTagIds = null;

    /** @var array<string, bool> per-request visibility by file id and mounted path */
    private array $cache = [];

    /** @var array<string, true> complete mounted ancestor chains checked clean for tags, not final visibility */
    private array $tagClean = [];

    /** whether the stored configuration could not be read as a list of tag IDs */
    private bool $configInvalid = false;

    /** whether the invalid configuration was already logged by this instance */
    private bool $invalidLogged = false;

    /** @var array<int, bool> per-request visibility in the owner's tree, independent of mounted paths */
    private array $ownerCache = [];

    /** @var array<string, array{node: Node, mount: string}|null> share root by ancestor id and mounted path */
    private array $shareRootCache = [];

    /**
     * @var array{reason?: string, folder?: Node}|null while explain() runs, the first reason the one decision of
     *      checkVisibility() refused for; null otherwise, so the tools' checks record nothing
     */
    private ?array $explanation = null;

    public function __construct(
        private IConfig $config,
        private ISystemTagObjectMapper $tagMapper,
        private ?IRootFolder $rootFolder = null,
        private ?ISystemTagManager $tagManager = null,
        private ?LoggerInterface $logger = null,
        ?\OCP\EventDispatcher\IEventDispatcher $events = null,
    ) {
        // Invalidate this very instance after OCP mutations outside the MCP tools,
        // too. Run after ordinary privacy hooks; tools also clear in finally.
        $invalidate = fn () => $this->clearCache();
        foreach ([
            \OCP\Files\Events\Node\NodeRenamedEvent::class,
            \OCP\Files\Events\Node\NodeCreatedEvent::class,
            \OCP\Files\Events\Node\NodeCopiedEvent::class,
            \OCP\Files\Events\Node\NodeDeletedEvent::class,
            \OCP\Files\Events\Node\NodeWrittenEvent::class,
            \OCP\SystemTag\MapperEvent::EVENT_ASSIGN,
            \OCP\SystemTag\MapperEvent::EVENT_UNASSIGN,
        ] as $event) {
            $events?->addListener($event, $invalidate, PHP_INT_MIN);
        }
    }

    /**
     * Checks whether a filesystem node is visible to the MCP caller.
     *
     * @param Node $node file or folder
     * @return bool false when the node or any ancestor has a hidden tag, or is a backup of a hidden file
     */
    public function isVisible(Node $node): bool {
        try {
            return $this->checkVisibility($node);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false;
        }
    }

    /**
     * Why the tools see or do not see a node, for the administrator's inspector (contract A.4, decision D5): the very
     * decision of isVisible(), with the reason recorded where it refuses, never a second evaluation of the rules. The
     * per-request caches are cleared first, since a cached refusal carries no reason.
     *
     * @param Node $node file or folder, resolved in the inspected user's tree
     * @return array{reason: string, folder: ?Node} reason is one of EXPLAINED_*; folder is the tagged ancestor for
     *         EXPLAINED_TAG_FOLDER, the only reason that names another node
     */
    public function explain(Node $node): array {
        $this->clearCache();
        $this->explanation = [];
        try {
            $visible = $this->isVisible($node);
            $why = $this->explanation;
        } finally {
            $this->explanation = null;
        }
        if ($visible) {
            return ['reason' => self::EXPLAINED_VISIBLE, 'folder' => null];
        }
        // A refusal that recorded nothing is never reported as a known reason.
        return ['reason' => $why['reason'] ?? self::EXPLAINED_NOT_EVALUATED, 'folder' => $why['folder'] ?? null];
    }

    /** Records the first reason of a refusal while explain() runs. */
    private function because(string $reason, ?Node $folder = null): void {
        if ($this->explanation !== null && !isset($this->explanation['reason'])) {
            $this->explanation = ['reason' => $reason] + ($folder === null ? [] : ['folder' => $folder]);
        }
    }

    private function checkVisibility(Node $node): bool {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            $this->because(self::EXPLAINED_INVALID_CONFIGURATION);
            return false;
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        // Only local share mounts need the owner's tree: ordinary mounts may
        // have no local owner. Detect shares before using the mounted-path cache.
        if (!$this->isVisibleThroughLocalShare($node, $hiddenTags)) {
            return false;
        }

        $nodeId = (int)$node->getId();
        $nodeKey = $nodeId . ':' . $node->getPath();
        if ($nodeId > 0 && isset($this->cache[$nodeKey])) {
            return $this->cache[$nodeKey];
        }

        // Collect ancestors up to root
        $chain = [];
        $current = $node;
        while (!$current instanceof IRootFolder) {
            $cid = (int)$current->getId();
            if ($cid <= 0 || isset($chain[$cid])) {
                $this->warnVisibilityFailure();
                return false;
            }

            // Mounted aliases can have different ancestors for the same file id.
            $cacheKey = $cid . ':' . $current->getPath();
            if (isset($this->cache[$cacheKey])) {
                if (!$this->cache[$cacheKey]) {
                    if ($nodeId > 0) {
                        $this->cache[$nodeKey] = false;
                    }
                    return false;
                }
                break;
            }

            if (isset($this->tagClean[$cacheKey])) {
                break; // tag ancestry only; the node's backup check must still run
            }

            $chain[$cid] = $current;
            try {
                $parent = $this->parentOf($current);
                if (!$parent instanceof Node || $parent === $current || (int)$parent->getId() === $cid) {
                    throw new \RuntimeException();
                }
                $current = $parent;
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return false;
            }
        }

        // Check tags in batch for unverified chain nodes
        if ($chain !== []) {
            $idsToCheck = array_map(static fn (int $id): string => (string)$id, array_keys($chain));
            try {
                $tagsByObject = $this->tagMapper->getTagIdsForObjects($idsToCheck, 'files');
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return false;
            }

            // Check from root down to node
            $reversedChain = array_reverse($chain, true);
            $foundHidden = false;
            foreach ($reversedChain as $cid => $chainNode) {
                $cacheKey = $cid . ':' . $chainNode->getPath();
                if ($foundHidden) {
                    $this->cache[$cacheKey] = false;
                    continue;
                }
                $nodeTags = array_map(strval(...), $tagsByObject[(string)$cid] ?? []);
                foreach ($nodeTags as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $foundHidden = true;
                        $this->cache[$cacheKey] = false;
                        if ($cid === $nodeId) {
                            $this->because(self::EXPLAINED_TAG_DIRECT);
                        } else {
                            $this->because(self::EXPLAINED_TAG_FOLDER, $chainNode);
                        }
                        break;
                    }
                }
            }

            if ($foundHidden) {
                if ($nodeId > 0) {
                    $this->cache[$nodeKey] = false;
                }
                return false;
            }
            // Publish only after the entire mapper result was checked successfully.
            foreach ($chain as $cid => $chainNode) {
                $this->tagClean[$cid . ':' . $chainNode->getPath()] = true;
            }
        }

        // Check /MCP backups mirror rule
        if ($this->isHiddenBackup($node, $chain)) {
            $this->because(self::EXPLAINED_BACKUP);
            if ($nodeId > 0) {
                $this->cache[$nodeKey] = false;
            }
            return false;
        }

        if ($nodeId > 0) {
            $this->cache[$nodeKey] = true;
        }
        return true;
    }

    /**
     * Wrappers report local shares via the public ISharedStorage interface.
     * Also inspect ancestors: a child can be on another storage under a share
     * mount. Core federated storages implement only the legacy OCA interface;
     * their locally configured tags are checked through the mounted chain.
     *
     * @param list<string> $hiddenTags
     */
    private function isVisibleThroughLocalShare(Node $node, array $hiddenTags): bool {
        try {
            $current = $node;
            $seen = [];
            $keys = [];
            $shareRoot = null;
            while (!$current instanceof IRootFolder) {
                if (isset($seen[spl_object_id($current)])) {
                    throw new \RuntimeException();
                }
                $seen[spl_object_id($current)] = $current;
                $id = (int)$current->getId();
                if ($id > 0) {
                    // A file id can occur through both personal and shared paths.
                    // Keep classification local to the mounted path, unlike tags.
                    $key = $id . ':' . $current->getPath();
                    if (array_key_exists($key, $this->shareRootCache)) {
                        $known = $this->shareRootCache[$key];
                        if ($shareRoot === null || ($known !== null && $known['mount'] === $shareRoot['mount'])) {
                            $shareRoot = $known;
                        }
                        break;
                    }
                    if ($current->getStorage()->instanceOfStorage(ISharedStorage::class)) {
                        $mount = $current->getMountPoint()->getMountPoint();
                        if ($mount === '') {
                            throw new \RuntimeException();
                        }
                        if ($shareRoot !== null && $mount !== $shareRoot['mount']) {
                            break; // a different mount is not part of this share
                        }
                        $shareRoot = ['node' => $current, 'mount' => $mount];
                    } elseif ($shareRoot !== null) {
                        break; // reached the parent outside the shared mount
                    }
                    $keys[] = $key;
                }
                // Nodes without an id cannot be memoized or resolved by id;
                // continue to their parent so an enclosing share is still checked.
                $parent = $this->parentOf($current);
                if (!$parent instanceof Node || $parent === $current
                    || ($id > 0 && (int)$parent->getId() === $id)) {
                    throw new \RuntimeException();
                }
                $current = $parent;
            }
            foreach ($keys as $key) {
                $this->shareRootCache[$key] = $shareRoot;
            }
            // Descendants below this root have the same mounted and owner chain;
            // only the omitted parents above the mount need canonical resolution.
            return $shareRoot === null || $this->isVisibleInOwnerTree($shareRoot['node'], $hiddenTags);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false;
        }
    }

    /**
     * Resolves metadata only; tools still access content through the caller's node
     * and its native permissions. getById covers every owner alias, unlike the
     * arbitrary single result of getFirstNodeById. No directory scan is needed.
     *
     * @param list<string> $hiddenTags
     */
    private function isVisibleInOwnerTree(Node $node, array $hiddenTags): bool {
        try {
            $id = (int)$node->getId();
            if ($id <= 0 || $this->rootFolder === null) {
                throw new \RuntimeException();
            }
            if (isset($this->ownerCache[$id])) {
                return $this->ownerCache[$id];
            }
            $owner = $node->getOwner();
            if ($owner === null || $owner->getUID() === '') {
                throw new \RuntimeException();
            }
            $folder = $this->rootFolder->getUserFolder($owner->getUID());
            $folderId = (int)$folder->getId();
            $origins = $id === $folderId ? [$folder] : $folder->getById($id);
            if ($origins === []) {
                throw new \RuntimeException();
            }
            $ids = [];
            foreach ($origins as $origin) {
                if (!$origin instanceof Node || (int)$origin->getId() !== $id) {
                    throw new \RuntimeException();
                }
                $current = $origin;
                $seen = [];
                while (true) {
                    $cid = (int)$current->getId();
                    if ($cid <= 0 || isset($seen[$cid])) {
                        throw new \RuntimeException();
                    }
                    $seen[$cid] = true;
                    $ids[$cid] = (string)$cid;
                    if ($cid === $folderId) {
                        break; // do not call Root::getParent()
                    }
                    $current = $this->parentOf($current);
                }
            }
            $tagsByObject = $this->tagMapper->getTagIdsForObjects(array_values($ids), 'files');
            foreach ($ids as $cid) {
                foreach ($tagsByObject[$cid] ?? [] as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $this->because(self::EXPLAINED_TAG_SHARE_ORIGIN);
                        return $this->ownerCache[$id] = false;
                    }
                }
            }
            return $this->ownerCache[$id] = true;
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false; // resolution failures are retried, never cached as visible
        }
    }

    /**
     * The parent of a node, with the file id the tag lookup must see.
     *
     * Above the root of a mount, core answers a lazy folder carrying the parent id the mounted storage records, not
     * the id of the folder the user sees there: -1 for an external storage root, an id of another storage for a
     * groupfolder, the source folder's id for a share. That folder is resolved again by its path, so its own tags are
     * the ones checked and the walk meets the real user folder. Inside a mount the parent id is the storage's own and
     * is kept. A parent that cannot be resolved again, or resolves to another path or to no id, is a failure.
     * Every walk up a chain and every comparison of a parent's id uses this, never Node::getParent() alone.
     *
     * @param Node $node node whose parent is needed
     * @return Node the parent; the root folder above the top of the tree
     * @throws \Throwable when the parent cannot be resolved
     */
    public function parentOf(Node $node): Node {
        $parent = $node->getParent();
        if ($parent instanceof IRootFolder || $this->rootFolder === null || !$this->isMountRoot($node)) {
            return $parent;
        }
        $path = dirname((string)$node->getPath());
        $resolved = $this->rootFolder->get($path);
        if (!$resolved instanceof Node || $resolved instanceof IRootFolder
            || $resolved->getPath() !== $path || (int)$resolved->getId() <= 0) {
            throw new \RuntimeException();
        }
        return $resolved;
    }

    /** Whether the node is the root of the mount it lives in, where core's parent id belongs to another storage. */
    private function isMountRoot(Node $node): bool {
        $mountPoint = $node->getMountPoint();
        if (!is_object($mountPoint)) {
            return false; // nothing marks a boundary, so the parent core gives is kept, as before
        }
        $mount = rtrim((string)$mountPoint->getMountPoint(), '/');
        return $mount !== '' && $mount === rtrim((string)$node->getPath(), '/');
    }

    /** Logs a privacy refusal without node names, paths or exception details. */
    private function warnVisibilityFailure(): void {
        $this->because(self::EXPLAINED_NOT_EVALUATED);
        $this->logger?->warning('MCP visibility check failed; access refused', ['app' => 'mcp']);
    }

    /** Logs, once per instance, that the stored hidden tags cannot be read, without the stored value. */
    private function warnInvalidConfiguration(): void {
        if ($this->invalidLogged) {
            return;
        }
        $this->invalidLogged = true;
        $this->logger?->warning('MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']);
    }

    /**
     * Asserts that a node is visible, throwing ToolFailure with FileAccessMessages::notFound() if hidden.
     *
     * @param Node $node
     * @throws ToolFailure
     */
    public function assertVisible(Node $node): void {
        if (!$this->isVisible($node)) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
    }

    /**
     * Whether a node may be crossed by metadata on the way to another one: an existing ancestor of a write
     * destination, the root a search starts from. Here that is visibility itself; {@see \OCA\Mcp\Access\ResourceScope\FilesAccessGuard}
     * leaves the resource scope out of it, since the ancestors of a selected folder are traversed, never shown.
     *
     * @param Node $node file or folder
     * @return bool false when the node may not be crossed
     */
    public function isTraversable(Node $node): bool {
        return $this->isVisible($node);
    }

    /**
     * @param Node $node file or folder about to be crossed
     * @throws ToolFailure with FileAccessMessages::notFound() when it may not be
     */
    public function assertTraversable(Node $node): void {
        if (!$this->isTraversable($node)) {
            throw new ToolFailure(FileAccessMessages::notFound());
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
        try {
            return $this->checkPathAllowedForWrite($root, $path);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false;
        }
    }

    private function checkPathAllowedForWrite(Folder $root, string $path): bool {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            return false;
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        if (!$this->isVisible($root)) {
            return false;
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
        $segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));
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
     * Copies hidden tags from the source or its ancestors before the empty backup receives content.
     *
     * @throws ToolFailure when tags or ancestors cannot be resolved, or tags cannot be assigned
     */
    public function copyHiddenTags(Node $source, Node $destination): void {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed());
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return;
        }

        $tagsToAssign = [];
        $current = $source;
        $seen = [];
        while (!$current instanceof IRootFolder) {
            $cid = (int)$current->getId();
            if ($cid <= 0 || isset($seen[$cid])) {
                $this->warnVisibilityFailure();
                throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed());
            }
            $seen[$cid] = true;
            try {
                $tags = $this->tagMapper->getTagIdsForObjects([(string)$cid], 'files');
                foreach ($tags[(string)$cid] ?? [] as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $tagsToAssign[(string)$tagId] = true;
                    }
                }
                $parent = $this->parentOf($current);
                if (!$parent instanceof Node || $parent === $current || (int)$parent->getId() === $cid) {
                    throw new \RuntimeException();
                }
                $current = $parent;
            } catch (\Throwable $e) {
                $this->warnVisibilityFailure();
                throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed(), 0, $e);
            }
        }

        if ($tagsToAssign !== []) {
            try {
                try {
                    $this->tagMapper->assignTags((string)$destination->getId(), 'files', array_map(strval(...), array_keys($tagsToAssign)));
                } finally {
                    $this->clearCache();
                }
            } catch (\Throwable $e) {
                $this->warnVisibilityFailure();
                throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\BackupMessages::failed(), 0, $e);
            }
        }
    }

    /**
     * @return list<string> configured hidden system tag IDs as strings; empty when none are configured
     *                      or when the stored value is invalid (see isConfigurationValid())
     */
    public function getHiddenTagIds(): array {
        if ($this->hiddenTagIds !== null) {
            return $this->hiddenTagIds;
        }
        try {
            $raw = $this->config->getAppValue('mcp', self::CONFIG_KEY, '[]');
        } catch (\Throwable) {
            // Retry on the next check; an unavailable config never means no exclusions.
            $this->configInvalid = true;
            return [];
        }
        // Objects stay objects: decoded as arrays, {} and {"0":"42"} would pass for lists.
        $decoded = json_decode($raw);
        $ids = [];
        $valid = is_array($decoded);
        foreach ($valid ? $decoded : [] as $id) {
            $str = is_int($id) || is_string($id) ? (string)$id : '';
            if (!ctype_digit($str) || (int)$str <= 0) {
                $valid = false;
                break;
            }
            $ids[] = $str;
        }
        // An unreadable value is not "no tags": the guard refuses every node until an admin saves again,
        // and the stored value is left untouched.
        $this->configInvalid = !$valid;
        $this->hiddenTagIds = $valid ? array_values(array_unique($ids)) : [];
        return $this->hiddenTagIds;
    }

    /** Whether the stored hidden tags can be read; when not, every node is refused. */
    public function isConfigurationValid(): bool {
        $this->getHiddenTagIds();
        return !$this->configInvalid;
    }

    /**
     * Saves the configured hidden system tag IDs.
     * Validates that each ID is a positive integer and corresponds to an existing system tag if tagManager is set.
     *
     * Removing every tag needs no system-tag lookup. An unreadable current configuration refuses any save;
     * unreadable system tags refuse nonempty selections. In both cases the previous value is kept.
     *
     * @param list<string|int> $tagIds
     * @throws \InvalidArgumentException when an ID is not a positive integer
     * @throws HiddenTagsUnavailableException when the current configuration or system tags cannot be checked
     */
    public function setHiddenTagIds(array $tagIds): void {
        $sanitized = [];
        foreach ($tagIds as $id) {
            $str = (string)$id;
            if (!ctype_digit($str) || (int)$str <= 0) {
                throw new \InvalidArgumentException('Tag ID must be a positive integer: ' . $str);
            }
            $sanitized[] = $str;
        }
        $sanitized = array_values(array_unique($sanitized));

        if ($this->tagManager !== null && $sanitized !== []) {
            try {
                $tags = $this->tagManager->getTagsByIds($sanitized);
                $existing = [];
                foreach ($tags as $tag) {
                    if ($tag instanceof ISystemTag) {
                        $existing[] = (string)$tag->getId();
                    }
                }
                $sanitized = array_values(array_intersect($sanitized, $existing));
            } catch (\Throwable) {
                try {
                    $all = $this->tagManager->getAllTags(null, false);
                    $valid = [];
                    foreach ($all as $t) {
                        if ($t instanceof ISystemTag) {
                            $valid[(string)$t->getId()] = true;
                        }
                    }
                    $sanitized = array_values(array_filter($sanitized, static fn (string $id): bool => isset($valid[$id])));
                } catch (\Throwable $e) {
                    throw new HiddenTagsUnavailableException('System tags could not be checked', 0, $e);
                }
            }
        }

        // Never replace exclusions after a failed read in this operation. Malformed
        // but readable values remain repairable through the admin panel.
        if ($this->hiddenTagIds === null && !$this->configInvalid) {
            $this->getHiddenTagIds();
        }
        if ($this->hiddenTagIds === null && $this->configInvalid) {
            throw new HiddenTagsUnavailableException('Hidden tags configuration could not be read');
        }

        $this->config->setAppValue('mcp', self::CONFIG_KEY, json_encode($sanitized));
        $this->hiddenTagIds = $sanitized;
        $this->configInvalid = false;
        $this->cache = [];
        $this->tagClean = [];
        $this->ownerCache = [];
        $this->shareRootCache = [];
    }

    /** Clears the in-memory caches. */
    public function clearCache(): void {
        $this->hiddenTagIds = null;
        $this->configInvalid = false;
        $this->cache = [];
        $this->tagClean = [];
        $this->ownerCache = [];
        $this->shareRootCache = [];
    }

    /**
     * Checks if $node is inside the backup folder, and if the original file still exists and is hidden.
     *
     * @param Node $node node being checked
     * @param array<int, Node> $chain the node and its not-yet-cached ancestors, keyed by file id, as collected by the caller
     * @return bool true when the node is a backup mirror of a hidden original
     */
    private function isHiddenBackup(Node $node, array $chain): bool {
        // Find ancestor named \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER
        $backupAncestor = null;
        $segmentsBelowBackup = [];
        $current = $node;

        while ($current instanceof Node) {
            if ($current instanceof IRootFolder) {
                break; // Root has no parent: Root::getParent() throws NotFoundException
            }
            if ($current->getName() === \OCA\Mcp\Platform\FileAccess\BackupLocation::FOLDER) {
                $backupAncestor = $current;
                break;
            }
            array_unshift($segmentsBelowBackup, $current->getName());
            try {
                $parent = $this->parentOf($current);
                if (!$parent instanceof Node || (int)$parent->getId() === (int)$current->getId()) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return true;
            }
        }

        if ($backupAncestor === null || $segmentsBelowBackup === []) {
            return false;
        }

        try {
            $userFolder = $this->parentOf($backupAncestor);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return true;
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
            // The original's own reason is not the backup's: it is recorded apart while the original is checked, and
            // only an original that could not be evaluated is carried over (A.4: the gate not evaluated is named).
            [$explaining, $this->explanation] = [$this->explanation, $this->explanation === null ? null : []];
            try {
                $hidden = !$this->isVisible($originalNode);
                $original = $this->explanation;
            } finally {
                $this->explanation = $explaining;
            }
            if ($hidden && $original !== null && ($original['reason'] ?? self::EXPLAINED_NOT_EVALUATED) === self::EXPLAINED_NOT_EVALUATED) {
                $this->because(self::EXPLAINED_NOT_EVALUATED);
            }
            return $hidden;
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return true;
        }
    }
}
