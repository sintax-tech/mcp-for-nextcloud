<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IConfig;

/** Resolves a client_id to its metadata: the optional native client, or a Client ID Metadata Document. */
class ClientResolver {
    public function __construct(
        private ClientMetadataFetcher $fetcher,
        private IConfig $config,
    ) {}

    /**
     * @param string $clientId client_id from the authorization request
     * @return ClientMetadata metadata of the client
     * @throws OAuthException invalid_client (never redirectable) when the client is unknown, disallowed or disabled
     */
    public function resolve(string $clientId): ClientMetadata {
        if ($clientId === NativeClient::CLIENT_ID) {
            if (!self::nativeEnabled($this->config)) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            return NativeClient::metadata();
        }
        return $this->fetcher->fetch($clientId);
    }

    /**
     * @param IConfig $config app configuration
     * @return bool true when the administrator enabled the native client
     */
    public static function nativeEnabled(IConfig $config): bool {
        return $config->getAppValue('mcp', NativeClient::CONFIG_KEY, '0') === '1';
    }
}
