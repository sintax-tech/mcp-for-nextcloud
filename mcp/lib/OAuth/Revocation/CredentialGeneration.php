<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Revocation;

use OCA\Mcp\OAuth\TokenOwnerGate;

use OCA\Mcp\Access\Policy\DecimalCounter;

/**
 * The validity rule of every OAuth credential (contracts §3.1), apart from the account and policy checks of the
 * TokenOwnerGate: its auth_global_epoch equals the global epoch and its auth_gen is above the user's revoked_below.
 * A 1.0.8 credential carries (0, 1) and a user without a generation row is (issue_seq 1, revoked_below 0), so the
 * upgrade keeps it valid until a revocation.
 */
final class CredentialGeneration {
    /**
     * @param array<string,mixed> $row code, grant or spent record with auth_global_epoch and auth_gen
     * @param array{issue_seq:string, revoked_below:string} $generations the owner's generations
     * @param string $epoch the global auth epoch
     * @return bool whether the credential is not revoked; a missing or non-canonical counter is revoked
     */
    public static function live(array $row, array $generations, string $epoch): bool {
        try {
            $generation = DecimalCounter::validate((string)($row['auth_gen'] ?? ''), DecimalCounter::MAX);
            $rowEpoch = DecimalCounter::validate((string)($row['auth_global_epoch'] ?? ''), DecimalCounter::MAX);
            return $rowEpoch === $epoch && DecimalCounter::compare($generation, $generations['revoked_below']) > 0;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
