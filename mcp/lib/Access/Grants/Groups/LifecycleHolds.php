<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCA\Mcp\Db\GroupLifeMapper;

final class LifecycleHolds {
    public function __construct(private GroupLifeMapper $life) {}

    public function hold(PolicyAttempt $attempt, array $lifecycles): void {
        $attempt->assertActive();
        if ($lifecycles === []) {
            return;
        }
        $groupIds = array_values(array_unique(array_column($lifecycles, 'groupId')));
        $current = [];
        foreach ($this->life->forGroups($groupIds) as $row) {
            $current[$row['groupId']] = $row;
        }
        foreach ($groupIds as $gid) {
            $row = $current[$gid] ?? null;
            if ($row === null || $row['state'] !== GroupLifeMapper::ACTIVE
                || !$this->life->transition($gid, $row['gen'], GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)))) {
                throw new SnapshotInvalidated();
            }
        }
    }
}
