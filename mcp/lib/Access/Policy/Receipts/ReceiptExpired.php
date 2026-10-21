<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

/** The caller's own receipt, past its time to live (\OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL). HTTP adapters map this to 410. */
class ReceiptExpired extends \RuntimeException {
    public function __construct() {
        parent::__construct('Receipt expired', 410);
    }
}
