<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

/**
 * What a tool asks about one resource of the authenticated user: is it inside the resource scope of its module
 * (contract B.5)? The answer comes from the policy snapshot of the current operation, so a change applies to the next
 * operation and one operation never mixes two scopes. Callers ask only after the registry admitted the tool by its
 * grant (F3 of the B4 review): the scope narrows what the grant allows and is never the first gate.
 */
interface ScopeReader {
    /**
     * @param string $userId authenticated user of the operation
     * @param string $module scope module (decision D1); a module without scope is never restricted
     * @param string $resourceId canonical decimal id of the resource (a calendar id for calendar and tasks)
     * @return bool whether the resource is inside the user's scope of that module; true when the module is unrestricted
     * @throws PolicyUnavailable when the scope cannot be read: never answered as "unrestricted"
     */
    public function inScope(string $userId, string $module, string $resourceId): bool;

    /**
     * Asked before anything about a resource is looked up natively, so that an unreadable scope answers the same
     * whether the resource exists or not (B6-R1): existence is never told apart by a 503 against a "not found".
     *
     * @param string $userId authenticated user of the operation
     * @param string $module scope module (decision D1)
     * @throws PolicyUnavailable when the scope of the module, or the policy, cannot be read
     */
    public function assertReadable(string $userId, string $module): void;
}
