<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/** Resources the target user cannot reach natively; nothing is written. */
final class ScopeItemsInaccessible extends \RuntimeException {
    /** @param list<string> $items the ids the target user cannot reach, in ascending numeric order */
    public function __construct(public readonly array $items) {
        parent::__construct('Resource not accessible to the user');
    }
}
