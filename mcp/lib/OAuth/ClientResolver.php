<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Db\OAuthClientsMapper;

/**
 * Resolves a client_id to its metadata: the optional native client, a self-hosted client, or a Client ID
 * Metadata Document.
 */
class ClientResolver {
    public function __construct(
        private ClientMetadataFetcher $fetcher,
        private OAuthClientsMapper $clients,
    ) {}

    /**
     * @param string $clientId client_id from the authorization request
     * @return ClientMetadata metadata of the client, with the generation it has now (native and self-hosted clients)
     * @throws OAuthException invalid_client (never redirectable) when the client is unknown, disallowed or disabled
     *         (a self-hosted client_id that is not stored, or whose client is disabled, counts as disabled; so does a
     *         stored row that does not validate)
     */
    public function resolve(string $clientId): ClientMetadata {
        try {
            if ($clientId === NativeClient::CLIENT_ID) {
                $generation = self::nativeGeneration($this->clients);
                if ($generation === null) {
                    throw new OAuthException('invalid_client', 'Unknown or not allowed client');
                }
                return NativeClient::metadata($generation);
            }
            if (CustomClient::owns($clientId)) {
                try {
                    $client = CustomClient::find($this->clients, $clientId);
                } catch (\UnexpectedValueException) {
                    $client = null;
                }
                if ($client === null || !$client['enabled'] || $client['redirectUris'] === []) {
                    throw new OAuthException('invalid_client', 'Unknown or not allowed client');
                }
                return CustomClient::metadata($client);
            }
            return $this->fetcher->fetch($clientId);
        } catch (\OCP\DB\Exception $e) {
            throw new \OCA\Mcp\Access\Policy\PolicyUnavailable('Cannot read OAuth client admission', previous: $e);
        }
    }

    /**
     * @param OAuthClientsMapper $clients the authoritative table, read from the database
     * @return bool true when the administrator enabled the native client
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function nativeEnabled(OAuthClientsMapper $clients): bool {
        return self::nativeGeneration($clients) !== null;
    }

    /**
     * @param OAuthClientsMapper $clients the authoritative table, read from the database
     * @return string|null the native client's generation while it is enabled; null when it is off, absent, or its row
     *     does not validate (fail-closed)
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function nativeGeneration(OAuthClientsMapper $clients): ?string {
        try {
            $row = $clients->find(NativeClient::CLIENT_ID);
        } catch (\UnexpectedValueException) {
            return null;
        }
        return $row !== null && $row['enabled'] ? $row['gen'] : null;
    }

    /**
     * @param OAuthClientsMapper $clients the authoritative table, read from the database
     * @param string $clientId client of a stored grant
     * @return bool true when the grant belongs to a built-in client the administrator has turned off, or to a
     *     self-hosted client that was turned off or deleted
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function builtInDisabled(OAuthClientsMapper $clients, string $clientId): bool {
        return $clientId === NativeClient::CLIENT_ID && !self::nativeEnabled($clients)
            || CustomClient::owns($clientId) && !CustomClient::active($clients, $clientId);
    }
}
