<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

/** The caller must reload a fresh state after rollback (HTTP 409). */
class PolicyConflict extends \RuntimeException {
    public function __construct() {
        parent::__construct('Policy changed since it was read', 409);
    }
}
