<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Resolution;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

/**
 * The policy was read, but the answer is indeterminate: it depends only on a group whose policy is indeterminate
 * (lost source, pending_delete, needs_confirm). Distinct from a failed read: a listing omits the one entry that depends
 * on it (fail-closed, the rest of the list stays usable), while a direct use of that entry still answers 503 like any
 * PolicyUnavailable. Never a denial, never a revocation.
 */
class GrantUnknown extends PolicyUnavailable {
    public function __construct(string $message = 'Policy resolution is unknown', ?\Throwable $previous = null) {
        parent::__construct($message, false, $previous);
    }
}
