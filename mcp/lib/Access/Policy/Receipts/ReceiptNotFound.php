<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

/**
 * A receipt the caller may not undo: unknown, invented, or created by another administrator. One answer for all of
 * them, so an id never reveals whether someone else's receipt exists. HTTP adapters map this to 404.
 */
class ReceiptNotFound extends \RuntimeException {
    public function __construct() {
        parent::__construct('Receipt not found', 404);
    }
}
