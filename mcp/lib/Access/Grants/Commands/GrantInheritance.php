<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use Closure;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Grants\GrantPolicy;

final class GrantInheritance {
    public const ELIGIBLE = 'eligible';
    public const LOST = 'lost';
    public const UNKNOWN = 'unknown';

    public function observe(array $cells, ?Closure $inheritance): array {
        $inherited = [];
        if ($inheritance === null) {
            return $inherited;
        }
        foreach ($cells as $cell) {
            if ($cell['key'] !== GrantPolicy::ELIGIBLE_KEY || $cell['value'] !== null) {
                continue;
            }
            try {
                $lifecycles = $inheritance($cell['uid']);
                $inherited[hash('sha256', $cell['uid'])] = $lifecycles === null || $lifecycles === []
                    ? ['state' => self::LOST]
                    : ['state' => self::ELIGIBLE, 'lifecycles' => $lifecycles];
            } catch (PolicyUnavailable) {
                $inherited[hash('sha256', $cell['uid'])] = ['state' => self::UNKNOWN];
            }
        }
        return $inherited;
    }
}
