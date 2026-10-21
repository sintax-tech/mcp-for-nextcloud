<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Db\RecheckMapper;
use OCP\IUserManager;
use Throwable;

/**
 * The external phase of the cause protocol (contracts §3.3.1 step 2, §3.3.4): what consuming each recheck cause of a
 * user does, from a fresh read of the account and of the resolved eligibility. It only reads, never under the mutex
 * and never persisting an observation (the resolver runs with observe=false), so it is safe in a pre-event handler
 * and in the external phase of a writer command.
 *
 * | Observed now | Consuming the cause |
 * | --- | --- |
 * | account missing, disabled or not eligible | cuts |
 * | eligibility unknown (read failure, group of indeterminate policy) | nothing: PolicyUnavailable (503, no revocation) |
 * | group_removal, eligible even without that group | does not cut |
 * | eligible; account_delete, or group_removal without which the user would not be eligible | cuts (Q1, §6.2) |
 *
 * A cause captured inside a caller's transaction has no stored row and no date; undated() classifies it instead, by
 * the exception to Q1 of §6.2.1: an eligible user is never cut.
 */
class CauseClassifier {
    public function __construct(private IUserManager $users, private PolicyResolver $resolver) {}

    /**
     * @param string $uid owner of the causes
     * @param list<array{causeKey:string, reason:string, groupId:?string, gen:string, createdAt:string}> $causes rows
     *     of RecheckMapper::causes()
     * @return array<string, bool> cause_key => whether consuming it cuts the user's generations; one entry per cause
     * @throws PolicyUnavailable when the account or the eligibility cannot be read; nothing may be consumed then
     */
    public function classify(string $uid, array $causes): array {
        $classified = [];
        if ($causes === []) {
            return $classified;
        }
        $lost = $this->lost($uid);
        foreach ($causes as $cause) {
            $classified[$cause['causeKey']] = $lost || match ($cause['reason']) {
                RecheckMapper::REASON_GROUP => !$this->eligible($uid, [(string)$cause['groupId']]),
                // Q1: an account deletion the user survived is still revoked; nothing is granted.
                default => true,
            };
        }
        return $classified;
    }

    /**
     * Reconciliation of a cause captured inside a caller's transaction (contracts §3.3.6, §6.2.1).
     *
     * @param string $uid the user of the captured event
     * @return bool true to cut (account missing, disabled or not eligible), false to drop it without cutting anything
     * @throws PolicyUnavailable when the account or the eligibility cannot be read; the item is kept for later
     */
    public function undated(string $uid): bool {
        return $this->lost($uid);
    }

    /**
     * Whether the user lost its access for good on a fresh read: no account, a disabled one, or not eligible.
     *
     * @throws PolicyUnavailable when the account or the eligibility cannot be read
     */
    private function lost(string $uid): bool {
        try {
            $user = $this->users->get($uid);
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the account of a recheck cause', previous: $e);
        }
        return $user === null || !$user->isEnabled() || !$this->eligible($uid, []);
    }

    /**
     * @param list<string> $without groups taken away by overlay, as if the user had already left them
     * @throws PolicyUnavailable when the eligibility is unknown or cannot be read
     */
    private function eligible(string $uid, array $without): bool {
        $context = $this->resolver->begin($uid, [], $without, false);
        try {
            $allowed = $context->eligible()->allowed;
        } finally {
            $context->close();
        }
        if ($allowed === null) {
            throw new PolicyUnavailable('Eligibility of a recheck cause is unknown');
        }
        return $allowed;
    }
}
