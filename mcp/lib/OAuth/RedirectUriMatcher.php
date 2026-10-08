<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/**
 * Exact redirect_uri matching against the client's registered list. Loopback URIs (127.0.0.1, [::1], localhost over
 * http) ignore the port, per RFC 8252 7.3, so native clients such as Claude Code can use an ephemeral port.
 */
class RedirectUriMatcher {
    private const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]', 'localhost'];

    /**
     * @param string $requested redirect_uri sent by the client
     * @param string[] $registered redirect_uris from the client metadata document
     * @return bool true when $requested equals a registered URI (port-agnostic for loopback)
     */
    public static function matches(string $requested, array $registered): bool {
        foreach ($registered as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            if ($requested === $candidate || self::sameLoopback($requested, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @return bool true when the URI is an http loopback address */
    public static function isLoopback(string $uri): bool {
        $parts = parse_url($uri);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true);
    }

    /**
     * Matches loopback redirect URIs while allowing the client to choose an ephemeral port.
     *
     * @param string $a requested redirect URI
     * @param string $b registered redirect URI
     * @return bool true when both are loopback URIs with equal non-port components
     */
    private static function sameLoopback(string $a, string $b): bool {
        if (!self::isLoopback($a) || !self::isLoopback($b)) {
            return false;
        }
        $pa = parse_url($a);
        $pb = parse_url($b);
        foreach (['host', 'path', 'query', 'user', 'pass', 'fragment'] as $key) {
            if (($pa[$key] ?? null) !== ($pb[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
