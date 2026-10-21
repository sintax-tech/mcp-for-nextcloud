<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyEventsMapper;

final class CredentialCuts {
    public function __construct(private AuthUsersMapper $authUsers, private PolicyStateMapper $state, private RecheckMapper $rechecks) {}

    public function consume(PolicyAttempt $attempt, string $uid, array $classified): int {
        $attempt->assertActive();
        if ($classified === []) {
            return 0;
        }
        if (in_array(true, array_column($classified, 'cut'), true)) {
            $this->user($attempt, $uid);
        }
        $consumed = 0;
        foreach ($classified as $cause) {
            if ($this->rechecks->consume($uid, $cause['causeKey'], $cause['gen'])) {
                ++$consumed;
            }
        }
        if ($consumed > 0) {
            $attempt->markCut();
        }
        return $consumed;
    }

    public function user(PolicyAttempt $attempt, string $uid, ?string $cut = null): void {
        $attempt->assertActive();
        $cut ??= $this->authUsers->generations($uid)['issue_seq'];
        if ($this->authUsers->cut($uid, $cut)) {
            $attempt->markCut();
        }
        $attempt->outbox()->add(PolicyEventsMapper::KIND_CLEANUP, PolicyEventPayload::cleanupUser($uid, $cut));
    }

    public function all(PolicyAttempt $attempt): void {
        $attempt->assertActive();
        $epoch = $this->state->advanceAuthEpoch($attempt->state()['auth_epoch']);
        $attempt->setAuthEpoch($epoch);
        $attempt->markCut();
        $attempt->outbox()->add(PolicyEventsMapper::KIND_CLEANUP, PolicyEventPayload::cleanupGlobal($epoch));
    }
}
