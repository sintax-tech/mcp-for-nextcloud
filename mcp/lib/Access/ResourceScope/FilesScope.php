<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ScopeResolution;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use RuntimeException;
use Throwable;

/**
 * The Files resource scope of a user (contracts B.2, B.5): a node is inside it when the node or an ancestor is one of
 * the selected folders, in the chain the user sees (mounted) or in the owner's chain above a share. The items come
 * from the policy snapshot of the operation ({@see GrantPolicy::scopeItems()}), so one operation never mixes two
 * scopes and a change applies to the next one.
 *
 * Ancestors are resolved by metadata only, with the parent the VisibilityGuard resolves above a mount root; nothing
 * is listed. A chain that cannot be resolved is outside the scope, never inside it. A scope that cannot be read is
 * {@see PolicyUnavailable} (503), never "unrestricted". Notes never asks (decision D4).
 */
class FilesScope {
    public const MODULE = 'files';

    /** @var array<string, list<string>> candidate ids by "id:path" of a node or an ancestor, until the tree changes */
    private array $chains = [];
    /** @var array<string, list<string>> the owner's chain of a share root, by its id, until the tree changes */
    private array $owners = [];

    public function __construct(
        private GrantPolicy $policy,
        private VisibilityGuard $guard,
        private IRootFolder $rootFolder,
        ?IEventDispatcher $events = null,
    ) {
        // A chain is structure, not policy: it changes with the tree, inside the MCP tools or outside them.
        $invalidate = fn () => $this->clearCache();
        foreach ([NodeRenamedEvent::class, NodeCopiedEvent::class, NodeDeletedEvent::class] as $event) {
            $events?->addListener($event, $invalidate, PHP_INT_MIN);
        }
    }

    /**
     * The user of the open operation, for the gate that is not handed one ({@see FilesAccessGuard}).
     *
     * @return string the authenticated user of the operation
     * @throws PolicyUnavailable when no single operation is open: whose scope applies is unknown, so nothing passes
     */
    public function operationUser(): string {
        return $this->policy->operationUser() ?? throw new PolicyUnavailable('No policy operation is open');
    }

    /**
     * @param string $userId authenticated user of the operation
     * @return list<string>|null the selected folder ids, possibly none, when Files is restricted; null when not
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function items(string $userId): ?array {
        return $this->policy->scopeItems($userId, self::MODULE);
    }

    /**
     * @param string $userId authenticated user of the operation
     * @return bool whether a Files scope narrows what this user's tools may reach
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function restricts(string $userId): bool {
        return $this->items($userId) !== null;
    }

    /**
     * @param string $userId authenticated user of the operation
     * @param Node $node a node of any tree, usually the user's own
     * @return bool true when Files is unrestricted, or when the node or one ancestor is a selected folder
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function allows(string $userId, Node $node): bool {
        $items = $this->items($userId);
        if ($items === null) {
            return true;
        }
        if ($items === []) {
            return false;
        }
        try {
            $candidates = $this->candidates($node);
        } catch (Throwable) {
            return false; // an unresolved chain never widens the scope
        }
        foreach ($candidates as $id) {
            // Stored ids are canonical decimals; only a byte-equal id matches.
            if (in_array($id, $items, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The selected folders as the user reaches them in their own tree, each with its real path, the outermost ones
     * only: what a listing of the user's root shows instead of the root itself. A folder the user no longer reaches
     * (deleted, unshared) is skipped, without saying it was selected.
     *
     * @param string $userId authenticated user of the operation
     * @param Folder $root the user's folder
     * @return list<Folder>|null the folders, by path; null when Files is unrestricted or the root itself is selected
     * @throws PolicyUnavailable when the scope cannot be read
     */
    public function roots(string $userId, Folder $root): ?array {
        $items = $this->items($userId);
        if ($items === null || in_array(self::canonical($root->getId()), $items, true)) {
            return null;
        }
        $found = [];
        foreach ($items as $id) {
            try {
                $nodes = $root->getById((int)$id);
            } catch (Throwable) {
                continue;
            }
            foreach ($nodes as $node) {
                if ($node instanceof Folder && self::canonical($node->getId()) === $id) {
                    $found[(string)$node->getPath()] = $node;
                }
            }
        }
        ksort($found, SORT_STRING);
        $outermost = [];
        foreach ($found as $path => $folder) {
            foreach (array_keys($outermost) as $kept) {
                if (str_starts_with($path, rtrim($kept, '/') . '/')) {
                    continue 2;
                }
            }
            $outermost[$path] = $folder;
        }
        return array_values($outermost);
    }

    /**
     * The candidates of a node for a caller that holds its own policy snapshot, such as the admin inspector: built
     * only when that snapshot restricts Files, exactly as {@see self::allows()} builds them, so a chain that cannot
     * be resolved never changes the answer for a user without a Files scope.
     *
     * @param ScopeResolution $scope the Files scope of the caller's snapshot
     * @param Node $node the node, as the user reaches it
     * @return list<string>|null the candidates when restricted; null otherwise (unrestricted, or unavailable, which
     *         the caller's resolution reports itself)
     * @throws Throwable when the scope is restricted and the chain cannot be resolved: then the node is outside
     */
    public function candidatesIn(ScopeResolution $scope, Node $node): ?array {
        return $scope->mode === ScopeResolution::RESTRICTED ? $this->candidates($node) : null;
    }

    /** Forgets the resolved chains: a move or a deletion changes them. */
    public function clearCache(): void {
        $this->chains = [];
        $this->owners = [];
    }

    /**
     * @param int|string|null $id a file id, as Node::getId() returns it
     * @return string the canonical decimal form the scope stores; '' for no id
     */
    public static function canonical(int|string|null $id): string {
        $id = (int)$id;
        return $id > 0 ? (string)$id : '';
    }

    /**
     * The ids that put a node inside the scope: its own and its ancestors' up to the top of the tree it is seen in,
     * and above each share mount root the owner's chain of that root, as the VisibilityGuard walks them. The one
     * definition of the Files candidates: the tools, the structured references and the admin inspector's explanation
     * all ask here, so the decision and its explanation never diverge.
     *
     * @param Node $node the node, as the user reaches it
     * @return list<string> canonical ids; the node is inside the scope when one of them is selected
     * @throws Throwable when a chain cannot be resolved: then the node is outside the scope
     */
    public function candidates(Node $node): array {
        // Walk up only until an ancestor whose chain is already known: the siblings of a listing, a tree or a search
        // share their ancestors, so each ancestor and each share root is resolved once per operation.
        $walked = [];
        $seen = [];
        $known = [];
        $current = $node;
        while (!$current instanceof IRootFolder) {
            $id = self::canonical($current->getId());
            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('Unresolved chain');
            }
            $key = $id . ':' . $current->getPath();
            if (isset($this->chains[$key])) {
                $known = $this->chains[$key];
                break;
            }
            $seen[$id] = true;
            $walked[] = [$key, $id, $current];
            $parent = $this->guard->parentOf($current);
            if ($parent === $current || ($parent instanceof Node && !$parent instanceof IRootFolder && self::canonical($parent->getId()) === $id)) {
                throw new RuntimeException('Unresolved chain');
            }
            $current = $parent;
        }
        // Each walked node's chain is its own id, the owner's chain above a share root, and its parent's chain.
        $chain = $known;
        foreach (array_reverse($walked) as [$key, $id, $walkedNode]) {
            $ids = [$id => $id];
            if ($this->isShareRoot($walkedNode)) {
                foreach ($this->ownerChain($walkedNode) as $owned) {
                    $ids[$owned] = $owned;
                }
            }
            foreach ($chain as $above) {
                $ids[$above] = $above;
            }
            $chain = $this->chains[$key] = array_values($ids);
        }
        return $chain;
    }

    /** Whether the node is the root of a share mount: above it, the user's chain is not the owner's. */
    private function isShareRoot(Node $node): bool {
        if (!$node->getStorage()->instanceOfStorage(ISharedStorage::class)) {
            return false;
        }
        $mount = rtrim((string)$node->getMountPoint()->getMountPoint(), '/');
        return $mount !== '' && $mount === rtrim((string)$node->getPath(), '/');
    }

    /**
     * Every chain of the node in its owner's tree, up to the owner's folder, by metadata only.
     *
     * @return list<string> canonical ids
     * @throws Throwable when the owner or a chain cannot be resolved
     */
    private function ownerChain(Node $node): array {
        $id = self::canonical($node->getId());
        if (isset($this->owners[$id])) {
            return $this->owners[$id];
        }
        $owner = $node->getOwner();
        if ($owner === null || $owner->getUID() === '') {
            throw new RuntimeException('No owner');
        }
        $folder = $this->rootFolder->getUserFolder($owner->getUID());
        $top = self::canonical($folder->getId());
        $origins = $id === $top ? [$folder] : $folder->getById((int)$id);
        if ($origins === []) {
            throw new RuntimeException('No origin');
        }
        $ids = [];
        foreach ($origins as $origin) {
            $current = $origin;
            $seen = [];
            while (true) {
                $cid = self::canonical($current->getId());
                if ($cid === '' || isset($seen[$cid])) {
                    throw new RuntimeException('Unresolved owner chain');
                }
                $seen[$cid] = true;
                $ids[$cid] = $cid;
                if ($cid === $top) {
                    break;
                }
                $current = $this->guard->parentOf($current);
            }
        }
        return $this->owners[$id] = array_values($ids);
    }
}
