<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCP\IUserManager;

final class PolicyUsers {
    public function __construct(private IUserManager $users) {}

    public function assertKnown(string $uid): void {
        if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
            throw new \InvalidArgumentException('Unknown user');
        }
    }
}
