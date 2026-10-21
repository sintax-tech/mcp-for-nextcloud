<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Resolution;

use OCA\Mcp\Access\ResourceScope\ScopeResolution;

use OCA\Mcp\Access\Policy\Resolution\GrantResolution;

/**
 * The first gate that blocks, in the order of the effective access: account, service, eligibility, connection, app,
 * logs, grant, then the resource scope (gate resource_scope, reason out_of_scope; resource_scope_unknown when the
 * scope could not be read). The exclusion tags and the native ACL come after, at the consumer.
 */
readonly class EffectiveResult {
    public function __construct(
        public ?bool $allowed,
        public string $reasonCode,
        public GrantResolution $grantResolution,
        public ?string $blockingGate,
        public ScopeResolution $scopeResolution,
    ) {}
}
