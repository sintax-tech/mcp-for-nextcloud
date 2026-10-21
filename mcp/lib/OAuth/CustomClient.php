<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use InvalidArgumentException;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/**
 * Self-hosted clients: public clients the administrator adds one by one for harnesses that take a manually
 * configured client_id and return to their own domain. Each has a name chosen by the administrator (shown on the
 * consent screen and in the connection lists), its own Enabled setting, the exact redirect URIs it may return to,
 * and a client_id "nextcloud-mcp-custom-" followed by 12 random hex characters. They use PKCE like every client;
 * redirect URIs match exactly as stored, loopback http included: the port is part of the match, unlike the native
 * client's (see ClientMetadata::allowsRedirect). With none added, none is accepted.
 *
 * The clients live in the app's own table, one row each (OAuthClientsMapper), read from the database by every decision
 * that admits or refuses a credential and written only by OAuthClientsService, in the same transaction that deletes
 * the credentials a change withdraws. The app configuration is never consulted: its cache loader can publish a
 * snapshot older than a withdrawal.
 *
 * Each stored client also has a generation, a canonical BIGINT that starts at 1 and advances whenever the client is
 * turned off or loses a redirect URI; an exhausted counter refuses the change instead of wrapping. A consent page opened before that carries the older generation and can no longer issue a code, even
 * if the client was turned on again or the URI added back (a deleted client has a new id when recreated).
 */
final class CustomClient {
    /** Every client_id starting with this is a self-hosted client, valid only while it is stored and enabled. */
    public const ID_PREFIX = 'nextcloud-mcp-custom';
    /** Pre-release storage in the app configuration; only the upgrade migration still reads it, to import it. */
    public const CLIENTS_KEY = 'oauth_custom_clients';
    public const MAX_CLIENTS = 20;
    public const MAX_NAME = 64;
    public const MAX_URIS = 20;
    /**
     * Longest redirect URI, in bytes (strlen): the redirect_uri column of the authorization codes is 2000 long, and
     * databases count that in characters or in bytes, so the byte length is the one that always fits.
     */
    public const MAX_LENGTH = 2000;

    /**
     * @param string $clientId client_id from a request or a stored grant
     * @return bool true when it belongs to the self-hosted namespace, whether or not such a client exists
     */
    public static function owns(string $clientId): bool {
        return str_starts_with($clientId, self::ID_PREFIX);
    }

    /** @return string the client_id of the stored client with this id */
    public static function clientId(string $id): string {
        return self::ID_PREFIX . '-' . $id;
    }

    /**
     * @param OAuthClientsMapper $table the authoritative table
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}> the stored clients
     * @throws \UnexpectedValueException for a stored row that does not validate
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function clients(OAuthClientsMapper $table): array {
        return array_map(self::client(...), $table->custom());
    }

    /**
     * @param OAuthClientsMapper $table the authoritative table
     * @param string $clientId client_id from a request or a stored grant
     * @return array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}|null the stored client, or null
     * @throws \UnexpectedValueException for a stored row that does not validate
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function find(OAuthClientsMapper $table, string $clientId): ?array {
        $row = self::owns($clientId) ? $table->find($clientId) : null;
        return $row === null || $row['kind'] !== OAuthClientsMapper::KIND_CUSTOM ? null : self::client($row);
    }

    /**
     * @param OAuthClientsMapper $table the authoritative table
     * @param string $clientId client_id of a stored grant
     * @return bool true when it is a self-hosted client that is stored and enabled; false also for a row that does
     *     not validate (fail-closed)
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function active(OAuthClientsMapper $table, string $clientId): bool {
        try {
            return self::find($table, $clientId)['enabled'] ?? false;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * @param OAuthClientsMapper $table the authoritative table
     * @return array<string, string> the name of each stored client by client_id
     * @throws \UnexpectedValueException for a stored row that does not validate
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function names(OAuthClientsMapper $table): array {
        $names = [];
        foreach (self::clients($table) as $client) {
            $names[self::clientId($client['id'])] = $client['name'];
        }
        return $names;
    }

    /** @param array{id:string, name:string, redirectUris:list<string>, gen:string} $client a stored client */
    public static function metadata(array $client): ClientMetadata {
        return new ClientMetadata(self::clientId($client['id']), $client['name'], $client['redirectUris'], $client['gen']);
    }

    /**
     * Whether a request of a stored client still stands: the client is stored, enabled, in the generation the request
     * was made in, and (given $redirectUri) still lists that redirect URI. A row that does not validate refuses.
     *
     * @param OAuthClientsMapper $table the authoritative table
     * @param string $clientId client_id of the request
     * @param string|null $generation generation of the client when the request was validated, null to accept any (a
     *     code or a grant: a withdrawal deletes those, so the generation only matters for a request not issued yet)
     * @param string|null $redirectUri redirect URI the request would return to, null for a grant that has none
     * @throws \OCP\DB\Exception when the query fails
     */
    public static function admits(OAuthClientsMapper $table, string $clientId, ?string $generation, ?string $redirectUri): bool {
        try {
            $client = self::find($table, $clientId);
        } catch (\UnexpectedValueException) {
            return false;
        }
        return $client !== null && $client['enabled'] && $client['redirectUris'] !== []
            && ($generation === null || $client['gen'] === $generation)
            && ($redirectUri === null || in_array($redirectUri, $client['redirectUris'], true));
    }

    /**
     * @param array{clientId:string, name:string, enabled:bool, redirectUris:list<string>, gen:string} $row a row of the table
     * @return array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}
     */
    private static function client(array $row): array {
        return ['id' => substr($row['clientId'], strlen(self::ID_PREFIX) + 1), 'name' => $row['name'], 'enabled' => $row['enabled'],
            'redirectUris' => $row['redirectUris'], 'gen' => $row['gen']];
    }

    /**
     * The list as it is stored: the validated clients with their generations, which advance for each client that is
     * withdrawn ($withdrawn, from withdrawn()). A client new to the list starts at 1. The counter is a canonical BIGINT
     * advanced by decimal carry: it never wraps nor falls back to 1.
     *
     * @param list<array{id:string, name:string, enabled:bool, redirectUris:list<string>}> $next validated clients
     * @param list<array{id:string, gen:string}> $before the stored clients
     * @param list<string> $withdrawn client_ids withdrawn()
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>, gen:string}>
     * @throws PolicyUnavailable when the generation of a withdrawn client is exhausted; nothing may be written then
     */
    public static function stamped(array $next, array $before, array $withdrawn): array {
        $gens = array_column($before, 'gen', 'id');
        $stamped = [];
        foreach ($next as $client) {
            $gen = $gens[$client['id']] ?? '1';
            if (in_array(self::clientId($client['id']), $withdrawn, true)) {
                try {
                    $gen = DecimalCounter::increment($gen);
                } catch (InvalidArgumentException $e) {
                    throw new PolicyUnavailable('OAuth client generation exhausted', previous: $e);
                }
            }
            $stamped[] = $client + ['gen' => $gen];
        }
        return $stamped;
    }

    /**
     * @param mixed $value request value: the full list of clients, each {id?, name, enabled, redirectUris}; an entry
     *     without id is a new client
     * @param list<array{id:string}> $current the stored clients
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>}> the clients to store, with a
     *     new random id for each new one; names and URIs trimmed, URIs without duplicates and otherwise verbatim
     * @throws InvalidArgumentException for a non-list, more than MAX_CLIENTS, an id that is not stored or repeated, a
     *     name that is empty, longer than MAX_NAME, has control characters or repeats another ignoring case, a
     *     non-boolean enabled, or redirect URIs that are not 1 to MAX_URIS acceptable ones (see validateUris)
     */
    public static function validate(mixed $value, array $current): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_CLIENTS) {
            throw new InvalidArgumentException('Invalid request');
        }
        $known = array_column($current, 'id');
        $ids = [];
        $names = [];
        $clients = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $id = $entry['id'] ?? null;
            if ($id === null) {
                $id = bin2hex(random_bytes(6));
            } elseif (!is_string($id) || !in_array($id, $known, true) || isset($ids[$id])) {
                throw new InvalidArgumentException('Invalid request');
            }
            $ids[$id] = true;
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            $key = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/u', $name) !== 0 || isset($names[$key])) {
                throw new InvalidArgumentException('Invalid request');
            }
            $names[$key] = true;
            if (!is_bool($entry['enabled'] ?? null)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uris = self::validateUris($entry['redirectUris'] ?? null);
            if ($uris === []) {
                throw new InvalidArgumentException('Invalid request');
            }
            $clients[] = ['id' => $id, 'name' => $name, 'enabled' => $entry['enabled'], 'redirectUris' => $uris];
        }
        return $clients;
    }

    /**
     * @param list<array{id:string, enabled:bool, redirectUris:list<string>}> $before the stored clients
     * @param list<array{id:string, enabled:bool, redirectUris:list<string>}> $after the clients about to be stored
     * @return list<string> client_ids whose grants must go: deleted, turned off, or missing a redirect URI they had
     */
    public static function withdrawn(array $before, array $after): array {
        $next = array_column($after, null, 'id');
        $withdrawn = [];
        foreach ($before as $client) {
            $now = $next[$client['id']] ?? null;
            if ($now === null || !$now['enabled'] || array_diff($client['redirectUris'], $now['redirectUris']) !== []) {
                $withdrawn[] = self::clientId($client['id']);
            }
        }
        return $withdrawn;
    }

    /**
     * @param mixed $value request value, which must be a list of redirect URIs (empty allowed)
     * @return list<string> the URIs, trimmed and without duplicates, stored verbatim otherwise
     * @throws InvalidArgumentException for a non-list, too many entries or any URI that is not an absolute https URI
     *         (http only on loopback) without userinfo, fragment, wildcard, whitespace or control characters
     */
    public static function validateUris(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_URIS) {
            throw new InvalidArgumentException('Invalid request');
        }
        $uris = [];
        foreach ($value as $uri) {
            if (!is_string($uri)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uri = trim($uri);
            if (!self::acceptable($uri)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uris[] = $uri;
        }
        return array_values(array_unique($uris));
    }

    /** @return bool true when $uri may be registered as a redirect URI of a self-hosted client */
    private static function acceptable(string $uri): bool {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f*#\\\\]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = $parts['scheme'] ?? '';
        return $scheme === 'https' || $scheme === 'http' && RedirectUriMatcher::isLoopback($uri);
    }
}
