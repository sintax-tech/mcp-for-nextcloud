<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Resolution;

/** null is unknown, never a denial that permits revocation or a permissive default. */
readonly class GrantResolution {
    /** @param list<string> $groupIds @param array{present:bool,value?:mixed} $rawState */
    public function __construct(
        public ?bool $allowed,
        public string $source,
        public array $groupIds,
        public array $rawState,
    ) {}
}
