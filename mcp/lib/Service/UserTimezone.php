<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCP\IConfig;

/**
 * Timezone a user reads dates in: their own preference, then the system default, then PHP's default.
 *
 * Same precedence Nextcloud uses in lib/private/DateTimeZone.php, but keyed by an explicit UID instead of
 * the session, because an MCP request authenticates by token and may carry no user session. An unknown or
 * empty name does not fail, it falls through to the next source.
 */
final class UserTimezone {
    public function __construct(private IConfig $config) {}

    /**
     * @param string $userId user whose preference decides
     * @return \DateTimeZone timezone to render or interpret that user's dates in
     */
    public function forUser(string $userId): \DateTimeZone {
        $candidates = [
            $this->config->getUserValue($userId, 'core', 'timezone', ''),
            $this->config->getSystemValueString('default_timezone', ''),
        ];
        foreach ($candidates as $candidate) {
            try {
                return new \DateTimeZone($candidate);
            } catch (\Exception) {
                // empty or unknown zone: try the next source in the precedence list
            }
        }
        return new \DateTimeZone(date_default_timezone_get());
    }
}
