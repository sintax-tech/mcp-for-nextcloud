<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IRequest;

/**
 * Derives the MCP resource URL, the issuer and the OAuth endpoint URLs from the current request, so the protected
 * resource metadata matches the exact URL the user pasted (with or without /index.php, under any webroot).
 * Scheme and host come from IRequest, which honours trusted_domains and the overwrite* settings.
 */
class ResourceUrl {
    private const APP_PATH = '/apps/mcp/';

    public function __construct(private IRequest $request) {}

    /** @return string MCP endpoint URL with trailing slash, e.g. https://host/apps/mcp/ */
    public function base(): string {
        $path = (string)parse_url($this->request->getRequestUri(), PHP_URL_PATH);
        $pos = strpos($path, self::APP_PATH);
        $path = $pos === false ? rtrim($path, '/') . '/' : substr($path, 0, $pos + strlen(self::APP_PATH));
        return $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $path;
    }

    /** @return string issuer identifier: the base URL without trailing slash */
    public function issuer(): string {
        return rtrim($this->base(), '/');
    }

    /** @return string URL of the protected resource metadata document */
    public function resourceMetadata(): string {
        return $this->base() . '.well-known/oauth-protected-resource';
    }

    /**
     * @param string $path path relative to the base URL, e.g. oauth/token
     * @return string absolute URL under the app
     */
    public function endpoint(string $path): string {
        return $this->base() . ltrim($path, '/');
    }

    /**
     * Compares two resource URLs ignoring scheme/host case, a trailing slash and the /index.php front controller.
     *
     * @return bool true when both identify the same MCP server
     */
    public static function sameResource(string $a, string $b): bool {
        return self::normalize($a) !== '' && self::normalize($a) === self::normalize($b);
    }

    /**
     * Normalizes a resource URL for comparison while ignoring scheme/host case and front-controller formatting.
     *
     * @param string $url resource URL to normalize
     * @return string normalized origin and path, or an empty string for an invalid URL
     */
    private static function normalize(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return '';
        }
        $path = str_replace('/index.php/', '/', ($parts['path'] ?? '') . '/');
        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($path, '/');
    }
}
