<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

final class CheckoutFailure extends \RuntimeException {
    public function __construct(public readonly int $status, string $message, public readonly array $payload = []) {
        parent::__construct($message);
    }
}
