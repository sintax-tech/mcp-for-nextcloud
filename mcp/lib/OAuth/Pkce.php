<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

/** PKCE (RFC 7636), S256 only: plain and absent challenges are refused. */
class Pkce {
    /**
     * @param string $challenge code_challenge from the authorization request
     * @return bool true when it is a base64url SHA-256 digest (43 chars)
     */
    public static function validChallenge(string $challenge): bool {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) === 1;
    }

    /**
     * @param string $verifier code_verifier from the token request
     * @param string $challenge stored code_challenge
     * @return bool true when the verifier is well formed and BASE64URL(SHA256(verifier)) equals the challenge
     */
    public static function verify(string $verifier, string $challenge): bool {
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !self::validChallenge($challenge)) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }
}
