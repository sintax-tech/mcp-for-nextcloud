<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Db\ResourceScopeMapper;

final class ScopeMutations {
    public function __construct(private ResourceScopeMapper $scopes) {}

    public function write(PolicyAttempt $attempt, string $uid, string $module, ?array $items, string $revision): ?array {
        $attempt->assertActive();
        $scopes = $this->scopes;
        try {
            $stored = $scopes->forModule($uid, $module);
            $before = PolicyEventPayload::scopeValue($stored === null ? null : $stored['items']);
            $after = PolicyEventPayload::scopeValue($items);
            if ($before === $after) {
                return null;
            }
            $scopes->replace($uid, $module, $items, $revision);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored resource scope', previous: $e);
        }
        return ['uid' => $uid, 'key' => PolicyEventPayload::SCOPE_PREFIX . $module, 'before' => $before, 'after' => $after];
    }
}
