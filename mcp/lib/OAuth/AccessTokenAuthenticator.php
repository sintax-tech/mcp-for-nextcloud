<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use OCP\IUser;
use Throwable;

/**
 * Resolves an `Authorization: Bearer` access token issued by this app into its user: the hash must exist, the token
 * must not be expired, a token of a managed client needs that client present and enabled in the authoritative table, its audience must be this MCP server,
 * its (epoch, generation) must still be valid (CredentialGeneration) and the owner must pass the TokenOwnerGate.
 * Validation takes no lock and writes nothing, except the consumption of the owner's pending recheck causes and the
 * cut of an owner observed unusable.
 */
class AccessTokenAuthenticator {
    public function __construct(
        private OAuthStore $store,
        private TokenHasher $hasher,
        private ITimeFactory $time,
        private TokenOwnerGate $gate,
        private AuthUsersMapper $authUsers,
        private PolicyStateMapper $state,
        private OAuthClientsMapper $clients,
    ) {}

    /** @return bool true when the header carries a token issued by this app */
    public static function isOwnBearer(string $authorization): bool {
        return preg_match('/^Bearer[ \t]+ncmcp_at_/i', $authorization) === 1;
    }

    /**
     * @param string $authorization raw Authorization header
     * @param string $resource resource URL of the current request
     * @return IUser|null the token owner, or null when the token is unknown, expired, for another audience or revoked
     * @throws PolicyUnavailable when the credential state cannot be read: the request fails, nothing is revoked
     */
    public function authenticate(string $authorization, string $resource): ?IUser {
        if (!self::isOwnBearer($authorization)) {
            return null;
        }
        try {
            $row = $this->store->findByAccess($this->hasher->hash(trim((string)preg_replace('/^Bearer[ \t]+/i', '', $authorization))));
            if ($row === null || !in_array('mcp', explode(' ', (string)$row['scope']), true)
                || (int)$row['access_expires'] < $this->time->getTime()
                || !ResourceUrl::sameResource((string)$row['resource'], $resource)) {
                return null;
            }
            $clientId = (string)$row['client_id'];
            // Read the authoritative client row before the owner gate and its observed generation cut.
            if (($clientId === NativeClient::CLIENT_ID || CustomClient::owns($clientId)) && ClientResolver::builtInDisabled($this->clients, $clientId)) {
                return null;
            }
            $uid = (string)$row['user_id'];
            // Pending causes first (contracts §3.3.4): a cut they demand is part of the generations read below.
            $this->gate->preflight($uid);
            // s0 of contracts §3.1 (iii): read before the owner check, and the only cut that check may apply.
            $generations = $this->authUsers->generations($uid);
            if (!CredentialGeneration::live($row, $generations, $this->state->state()['auth_epoch'])) {
                return null;
            }
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the credential state', previous: $e);
        }
        return $this->gate->owner($uid, $generations['issue_seq']);
    }
}
