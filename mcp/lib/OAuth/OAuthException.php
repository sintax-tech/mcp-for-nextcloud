<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use RuntimeException;

/**
 * An OAuth error with its RFC 6749 code. Descriptions are fixed texts: never tokens, codes or verifiers.
 * Errors that are not redirectable (unknown client, unregistered redirect_uri) must be shown locally, never sent
 * to the redirect_uri.
 */
class OAuthException extends RuntimeException {
    /**
     * @param string $error RFC 6749 error code (invalid_request, invalid_grant, ...)
     * @param string $description fixed human-readable text
     * @param bool $redirectable whether the error may be reported to the client's redirect_uri
     */
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly bool $redirectable = false,
    ) {
        parent::__construct($description);
    }
}
