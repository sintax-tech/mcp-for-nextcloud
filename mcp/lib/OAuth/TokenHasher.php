<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IConfig;

/** Generates high-entropy opaque tokens and the keyed hash (HMAC-SHA256 with the instance secret) that is stored. */
class TokenHasher {
    public function __construct(private IConfig $config) {}

    /**
     * @param string $kind short token kind used in the prefix (at, rt, code)
     * @return string a new token, e.g. ncmcp_at_<43 base64url chars> (256 random bits)
     */
    public function generate(string $kind): string {
        return 'ncmcp_' . $kind . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return string 64-char hex HMAC of the token; the plain token is never persisted */
    public function hash(string $token): string {
        return hash_hmac('sha256', $token, 'mcp-oauth|' . $this->config->getSystemValueString('secret', ''));
    }
}
