<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth\Connections;

use InvalidArgumentException;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Read model and revocation of the active OAuth connections (one row of mcp_oauth_tokens each) for the admin and
 * personal settings pages. A connection is identified by its grant id, user, client and dates; token hashes are
 * never part of it. Passing a uid scopes every call to that user's own connections.
 */
class ConnectionList {
    /** Connections per page. */
    public const PAGE_SIZE = 50;
    /** Highest page accepted, to bound offsets. */
    public const MAX_PAGE = 10000;
    /** Longest search term accepted. */
    public const MAX_SEARCH = 100;

    public function __construct(
        private OAuthStore $store,
        private TokenService $tokens,
        private IUserManager $userManager,
        private ITimeFactory $time,
        private OAuthClientsMapper $clients,
    ) {}

    /**
     * @param string|null $uid owner to restrict to, null for every user
     * @param string $search part of the user id or client_id, '' for none
     * @param int $page 1-based page
     * @return array{connections: list<array{id:int, uid:string, displayName:string, client:array{kind:string, host:string, id:string, name?:string}, createdAt:int, expiresAt:int}>, page:int, pageSize:int, hasMore:bool}
     * @throws InvalidArgumentException for a page out of range or an oversized term
     */
    public function page(?string $uid, string $search, int $page): array {
        $search = trim($search);
        if ($page < 1 || $page > self::MAX_PAGE || mb_strlen($search) > self::MAX_SEARCH) {
            throw new InvalidArgumentException('Invalid request');
        }
        $rows = $this->store->listGrants($uid, $search, self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE, $this->time->getTime());
        $hasMore = count($rows) > self::PAGE_SIZE;
        $names = [];
        try {
            $clients = CustomClient::names($this->clients);
        } catch (\UnexpectedValueException) {
            // A row that does not validate names nobody; the list still shows the client_id.
            $clients = [];
        }
        $connections = array_map(function (array $row) use (&$names, $clients): array {
            $owner = $row['user_id'];
            $names[$owner] ??= $this->userManager->get($owner)?->getDisplayName() ?? $owner;
            return [
                'id' => $row['id'],
                'uid' => $owner,
                'displayName' => $names[$owner],
                'client' => self::client($row['client_id'], $clients),
                'createdAt' => $row['created_at'],
                'expiresAt' => $row['refresh_expires'],
            ];
        }, array_slice($rows, 0, self::PAGE_SIZE));
        return ['connections' => $connections, 'page' => $page, 'pageSize' => self::PAGE_SIZE, 'hasMore' => $hasMore];
    }

    /** @return int number of live connections of every user, for the status summary */
    public function count(): int {
        return $this->store->countGrants($this->time->getTime());
    }

    /**
     * Revokes one connection at once: its access and refresh tokens stop working on the next request.
     *
     * @param int $id grant id
     * @param string|null $uid required owner, null for an administrator
     * @return bool true when it existed (and, with $uid, belonged to that user)
     */
    public function revoke(int $id, ?string $uid): bool {
        return $id > 0 && $this->tokens->revokeGrant($id, $uid);
    }

    /**
     * Revokes every connection of a user, by a cut at the user's last issued generation. The user stays eligible and
     * connected and can sign in again; the new consent gets a later generation.
     *
     * @param string $uid Nextcloud user id
     */
    public function revokeUser(string $uid): void {
        $this->tokens->revokeUser($uid);
    }

    /**
     * @param string $clientId client_id of the grant: a CIMD URL, the native client id or a self-hosted one
     * @param array<string, string> $customNames names of the self-hosted clients by client_id
     * @return array{kind:string, host:string, id:string, name?:string} kind 'native', 'custom' or 'web', the host shown
     *     to people and the raw id; a self-hosted client also has its name ('' when it was deleted)
     */
    public static function client(string $clientId, array $customNames = []): array {
        if ($clientId === NativeClient::CLIENT_ID) {
            return ['kind' => 'native', 'host' => '', 'id' => $clientId];
        }
        if (CustomClient::owns($clientId)) {
            return ['kind' => 'custom', 'host' => '', 'id' => $clientId, 'name' => $customNames[$clientId] ?? ''];
        }
        $host = parse_url($clientId, PHP_URL_HOST);
        return ['kind' => 'web', 'host' => is_string($host) && $host !== '' ? strtolower($host) : $clientId, 'id' => $clientId];
    }
}
