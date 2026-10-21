<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

/**
 * The resource scope of one module for one user, as one operation read it (contracts B.1, B.5). Raw is the mode row
 * and its items; resolved is the mode with the count. Unavailable is a read that failed: never "unrestricted".
 */
readonly class ScopeResolution {
    public const UNRESTRICTED = 'unrestricted';
    public const RESTRICTED = 'restricted';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param string $mode one of the constants
     * @param list<string> $items allowed resource ids when restricted, possibly none; empty otherwise
     * @param string|null $policyRev revision of the commit that saved the restriction, when restricted
     */
    public function __construct(
        public string $mode,
        public array $items = [],
        public ?string $policyRev = null,
    ) {}

    /** @return int|null how many resources a restricted module keeps; null when not restricted */
    public function count(): ?int {
        return $this->mode === self::RESTRICTED ? count($this->items) : null;
    }
}
