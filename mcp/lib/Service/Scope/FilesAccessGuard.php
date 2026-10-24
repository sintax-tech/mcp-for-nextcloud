<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Scope;

use OCA\Mcp\Service\GuestRestrictions;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\VisibilityGuard;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * The gate of the Files surface: the tools of the Files module, nc://files and checkout (contract B.5). It is the
 * VisibilityGuard preceded by the Files resource scope of the operation's user, in the order of the effective access
 * (grant → scope → exclusion tags → native ACL): a node outside the scope is refused exactly like a hidden or missing
 * one (D6), and a scope that cannot be read fails the operation with 503 ({@see PolicyUnavailable}).
 *
 * The scope applies to what the tools reach. Ancestors crossed by metadata on the way to a destination
 * ({@see self::isTraversable()}) answer to the exclusion only, so a folder can be created at or below a selected folder
 * without its ancestors becoming reachable. Notes (decision D4), the admin pages and the native enumeration keep the
 * plain VisibilityGuard. Without a FilesScope (unit tests) this is the plain VisibilityGuard.
 */
class FilesAccessGuard extends VisibilityGuard {
    public function __construct(
        IConfig $config,
        ISystemTagObjectMapper $tagMapper,
        ?IRootFolder $rootFolder = null,
        ?ISystemTagManager $tagManager = null,
        ?LoggerInterface $logger = null,
        ?IEventDispatcher $events = null,
        private ?FilesScope $scope = null,
        private ?GuestRestrictions $guests = null,
        ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
    ) {
        parent::__construct($config, $tagMapper, $rootFolder, $tagManager, $logger, $events, $backupLocation);
    }

    /**
     * Inside the operation user's Files scope, then visible.
     *
     * @throws PolicyUnavailable when the scope cannot be read or no operation is open
     */
    public function isVisible(Node $node): bool {
        if ($this->scope !== null && !$this->scope->allows($this->scope->operationUser(), $node)) {
            return false;
        }
        return $this->reachable($node) && parent::isVisible($node);
    }

    /** The exclusion alone: a crossed ancestor is never shown, so the scope does not apply to it. */
    public function isTraversable(Node $node): bool {
        return $this->reachable($node) && parent::isVisible($node);
    }

    /**
     * Outside external storage the Guests app hides from the operation's user, whatever the path that resolved the node
     * ({@see GuestRestrictions::hidesExternalStorage()}).
     *
     * @throws PolicyUnavailable when no operation is open
     */
    private function reachable(Node $node): bool {
        if ($this->guests === null || $this->scope === null || !$this->guests->hidesExternalStorage($this->scope->operationUser())) {
            return true;
        }
        try {
            return !str_starts_with((string)$node->getMountPoint()->getMountType(), 'external');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reads the scope of the operation once, before the tool does anything, so a scope that cannot be read answers
     * 503 even where a later check would swallow the failure as a plain refusal.
     *
     * @throws PolicyUnavailable when the scope cannot be read or no operation is open
     */
    public function assertScopeReadable(): void {
        if ($this->scope !== null) {
            $this->scope->items($this->scope->operationUser());
        }
    }

    /**
     * What a listing of the user's root shows when the root itself is outside the scope: the selected folders the
     * user reaches, with their real paths, still subject to the exclusion and the native read.
     *
     * @param Folder $root the user's folder
     * @return list<Folder>|null the folders; null when the root itself may be listed
     * @throws PolicyUnavailable when the scope cannot be read or no operation is open
     */
    public function scopeRoots(Folder $root): ?array {
        $roots = $this->scope?->roots($this->scope->operationUser(), $root);
        if ($roots === null) {
            return null;
        }
        return array_values(array_filter($roots, fn (Folder $folder): bool => $folder->isReadable() && $this->reachable($folder) && parent::isVisible($folder)));
    }

    public function clearCache(): void {
        parent::clearCache();
        $this->scope?->clearCache();
    }
}
