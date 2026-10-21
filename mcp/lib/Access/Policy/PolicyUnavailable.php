<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;

/** Storage failure; HTTP adapters map this to 503 and preserve the draft. */
class PolicyUnavailable extends \RuntimeException {
    public function __construct(string $message = 'Policy storage unavailable', public readonly bool $uncertain = false, ?\Throwable $previous = null) {
        parent::__construct($message, 503, $previous);
    }
}
