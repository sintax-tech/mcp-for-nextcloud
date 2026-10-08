<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;

/**
 * Resolves an `Authorization: Bearer` access token issued by this app into its user: the hash must exist, the token
 * must not be expired, a native-client token needs the native client enabled, its audience must be this MCP server and the owner must pass the TokenOwnerGate.
 */
class AccessTokenAuthenticator {
    public function __construct(
        private OAuthStore $store,
        private TokenHasher $hasher,
        private ITimeFactory $time,
        private TokenOwnerGate $gate,
        private IConfig $config,
    ) {}

    /** @return bool true when the header carries a token issued by this app */
    public static function isOwnBearer(string $authorization): bool {
        return preg_match('/^Bearer[ \t]+ncmcp_at_/i', $authorization) === 1;
    }

    /**
     * @param string $authorization raw Authorization header
     * @param string $resource resource URL of the current request
     * @return IUser|null the token owner, or null when the token is unknown, expired, for another audience or revoked
     */
    public function authenticate(string $authorization, string $resource): ?IUser {
        if (!self::isOwnBearer($authorization)) {
            return null;
        }
        $row = $this->store->findByAccess($this->hasher->hash(trim((string)preg_replace('/^Bearer[ \t]+/i', '', $authorization))));
        if ($row === null || !in_array('mcp', explode(' ', (string)$row['scope']), true)
            || (int)$row['access_expires'] < $this->time->getTime()
            || !ResourceUrl::sameResource((string)$row['resource'], $resource)) {
            return null;
        }
        if ($row['client_id'] === NativeClient::CLIENT_ID && !ClientResolver::nativeEnabled($this->config)) {
            return null;
        }
        return $this->gate->owner((string)$row['user_id']);
    }
}
