<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ScopeResolution;
use Throwable;

/**
 * The resource scope of one user for one operation (contract B.5), separate from the VisibilityGuard. The modes and
 * items are read once, when the PolicyOperationContext is assembled, and never again during that operation. A scope
 * only narrows: it is evaluated after the operation's grant and before the exclusion tags and the native ACL, and
 * selecting a resource never grants access to it.
 *
 * A failed read, a digest collision or an inconsistent row included, makes every scope module unavailable for the
 * operation, with the storage unavailability of the group plan; it is never read as "unrestricted". Modules without a
 * scope (decision D1) are unaffected. Nothing here is stamped into a credential: the next operation reads again.
 */
final class ScopeGuard {
    /**
     * @param array<string, array{policyRev:string, items:list<string>}>|null $scopes restricted modules; null when
     *        the read failed
     */
    private function __construct(private ?array $scopes) {}

    /**
     * @param ResourceScopeMapper $mapper scope storage
     * @param string|null $uid user of the operation; null for an account that does not exist, which has no scope
     * @return self the scope as read now, or an unavailable one when the read failed
     */
    public static function read(ResourceScopeMapper $mapper, ?string $uid): self {
        if ($uid === null) {
            return new self([]);
        }
        try {
            return new self($mapper->forUser($uid));
        } catch (Throwable) {
            return new self(null);
        }
    }

    /**
     * @param string $module any catalog module
     * @return ScopeResolution unrestricted for a module without scope or without a mode row; unavailable for a scope
     *         module whose read failed
     */
    public function resolution(string $module): ScopeResolution {
        if (!in_array($module, ResourceScopeMapper::MODULES, true)) {
            return new ScopeResolution(ScopeResolution::UNRESTRICTED);
        }
        if ($this->scopes === null) {
            return new ScopeResolution(ScopeResolution::UNAVAILABLE);
        }
        $scope = $this->scopes[$module] ?? null;
        return $scope === null
            ? new ScopeResolution(ScopeResolution::UNRESTRICTED)
            : new ScopeResolution(ScopeResolution::RESTRICTED, $scope['items'], $scope['policyRev']);
    }

    /**
     * Whether a resource is inside the scope. For Files a node is inside when it or any ancestor, in the mounted or
     * the owner's chain, is an item: the caller passes every candidate id and one match is enough.
     *
     * @param string $module any catalog module
     * @param list<string> $resourceIds canonical decimal ids of the resource and, for Files, of its ancestors
     * @return bool true when unrestricted or when one id is an item; false otherwise, including an empty list
     * @throws PolicyUnavailable when the scope of the module could not be read
     */
    public function allows(string $module, array $resourceIds): bool {
        $resolution = $this->resolution($module);
        if ($resolution->mode === ScopeResolution::UNAVAILABLE) {
            throw new PolicyUnavailable('Resource scope unavailable');
        }
        if ($resolution->mode === ScopeResolution::UNRESTRICTED) {
            return true;
        }
        foreach ($resourceIds as $id) {
            // Stored ids are canonical decimals; only a byte-equal id matches.
            if (is_string($id) && in_array($id, $resolution->items, true)) {
                return true;
            }
        }
        return false;
    }
}
