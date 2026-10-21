<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\Revocation\CredentialCommands;
use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use OCA\Mcp\OAuth\Revocation\OwnerNotUsable;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use Throwable;
use Closure;

/**
 * Issues authorization codes and token grants and runs the token endpoint grants (authorization_code with PKCE,
 * refresh_token with rotation). Codes and refresh tokens are single use; only their hashes are stored.
 *
 * Every unit that writes OAuth state is one credential transaction (contracts §2.1.1, §3.1): the owner is checked in the
 * external phase; under the global mutex the user's generations and causes are compared with what was read before,
 * and consumption and issuance commit together. Consent, exchange and refresh first consume the owner's pending
 * recheck causes in that same transaction, with the cut each demands (contracts §3.3.4), so nothing they issue can be
 * cut later by an older cause. A code carries the user's new generation, a grant inherits it from
 * its code and rotation keeps it, so one revocation by cut reaches the whole lineage and nothing issued after it.
 * Storage failures surface as PolicyUnavailable, which the token endpoint answers with temporarily_unavailable.
 */
class TokenService {
    public const CODE_TTL = 300;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 30 * 86400;

    public function __construct(
        private OAuthStore $store,
        private TokenHasher $hasher,
        private ITimeFactory $time,
        private TokenOwnerGate $gate,
        private CredentialCommands $credentials,
        private AuthUsersMapper $authUsers,
        private PolicyEventProcessor $events,
        private OAuthClientsMapper $clients,
        private PolicyTransaction $transaction,
        private LifecycleHolds $holds,
    ) {}

    /**
     * Consent: turns the user's connection on and stores a one-time code in one atomic command; if either fails,
     * neither happens.
     *
     * @param AuthorizationRequest $request the consented request
     * @param string $uid the consenting user
     * @return string|null the one-time authorization code, or null when the service is off, the account is disabled
     *     or the user is not eligible (nothing was written)
     * @throws \InvalidArgumentException for a user the server does not know
     * @throws OAuthException invalid_client when, under the mutex, the client was turned off, deleted or withdrawn
     *     (a redirect URI removed, or turned off and on again) since the request was validated; nothing was written
     * @throws PolicyUnavailable when the eligibility is unknown, the policy storage fails or keeps changing; nothing
     *     was issued
     */
    public function consent(AuthorizationRequest $request, string $uid): ?string {
        $code = $this->hasher->generate('code');
        $allowed = $this->credentials->consent($uid, fn (): ?array => $this->gate->admission($uid, false),
            function (string $generation, string $epoch) use ($request, $uid, $code): void {
            // Re-read client admission under the mutex shared with administrative withdrawal.
            if (!$this->clientAdmitted($request->client->clientId, $request->client->generation, $request->redirectUri)) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            $now = $this->time->getTime();
            $this->store->insertCode([
                'code_hash' => $this->hasher->hash($code),
                'user_id' => $uid,
                'client_id' => $request->client->clientId,
                'redirect_uri' => $request->redirectUri,
                'code_challenge' => $request->codeChallenge,
                'resource' => $request->resource,
                'scope' => $request->scope,
                'expires_at' => $now + self::CODE_TTL,
                'auth_global_epoch' => $epoch,
                'auth_gen' => $generation,
            ], $now);
        }, $this->gate->causes());
        return $allowed ? $code : null;
    }

    /**
     * @param array<string,mixed> $params form parameters of the token request (grant_type=authorization_code)
     * @return array<string,string|int> the token response body
     * @throws OAuthException invalid_request / invalid_grant
     * @throws PolicyUnavailable when the storage fails or keeps changing; the code is not consumed then
     */
    public function exchangeCode(array $params): array {
        $get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
        if ($get('code') === '' || $get('code_verifier') === '' || $get('client_id') === '') {
            throw new OAuthException('invalid_request', 'code, code_verifier and client_id are required');
        }
        $hash = $this->hasher->hash($get('code'));
        $known = $this->read(fn () => $this->store->findCode($hash));
        if ($known === null) {
            throw new OAuthException('invalid_grant', 'Invalid authorization code');
        }
        $uid = (string)$known['user_id'];
        $observed = $this->read(fn () => $this->authUsers->generations($uid))['issue_seq'];
        $error = null;
        $body = null;
        $this->asOwner($uid, $observed, function (array $state, array $generations) use ($hash, $get, $uid, &$error, &$body): void {
            $error = null;
            $body = null;
            // Any presentation spends the code; the checks below never give it back.
            $row = $this->store->consumeCode($hash);
            if ($row === null || (int)$row['expires_at'] < $this->time->getTime() || (string)$row['user_id'] !== $uid
                || $row['client_id'] !== $get('client_id') || $row['redirect_uri'] !== $get('redirect_uri')
                || !Pkce::verify($get('code_verifier'), (string)$row['code_challenge'])
                || ($get('resource') !== '' && !ResourceUrl::sameResource($get('resource'), (string)$row['resource']))
                || !CredentialGeneration::live($row, $generations, $state['auth_epoch'])) {
                $error = new OAuthException('invalid_grant', 'Invalid authorization code');
                return;
            }
            if (!$this->clientAdmitted((string)$row['client_id'], null, (string)$row['redirect_uri'])) {
                $error = new OAuthException('invalid_grant', 'This client is disabled');
                return;
            }
            $body = $this->issue($uid, (string)$row['client_id'], (string)$row['resource'], (string)$row['scope'],
                (string)$row['auth_gen'], $state['auth_epoch']);
        });
        if ($error !== null) {
            throw $error;
        }
        return $body;
    }

    /**
     * @param array<string,mixed> $params form parameters of the token request (grant_type=refresh_token)
     * @return array<string,string|int> the token response body with a new refresh token
     * @throws OAuthException invalid_request / invalid_grant
     * @throws PolicyUnavailable when the storage fails or keeps changing; the refresh token is not consumed then
     */
    public function refresh(array $params): array {
        $get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
        if ($get('refresh_token') === '' || $get('client_id') === '') {
            throw new OAuthException('invalid_request', 'refresh_token and client_id are required');
        }
        $oldHash = $this->hasher->hash($get('refresh_token'));
        $row = $this->read(fn () => $this->store->findByRefresh($oldHash));
        $now = $this->time->getTime();
        if ($row === null) {
            $this->replayed($oldHash, $get('client_id'));
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        if ((int)$row['refresh_expires'] < $now || $row['client_id'] !== $get('client_id')) {
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        $uid = (string)$row['user_id'];
        $observed = $this->read(fn () => $this->authUsers->generations($uid))['issue_seq'];
        $access = $this->hasher->generate('at');
        $refresh = $this->hasher->generate('rt');
        $lost = false;
        $revoked = false;
        $disabled = false;
        $this->asOwner($uid, $observed, function (array $state, array $generations) use ($oldHash, $row, $now, $access, $refresh, &$lost, &$revoked, &$disabled): void {
            [$lost, $revoked, $disabled] = [false, false, false];
            $current = $this->store->findByRefresh($oldHash);
            if ($current === null || (int)$current['id'] !== (int)$row['id']) {
                $lost = true;
                return;
            }
            if (!CredentialGeneration::live($current, $generations, $state['auth_epoch'])) {
                $revoked = true;
                return;
            }
            // Under the mutex, not before it: a client withdrawn after the external phase refuses this rotation.
            if (!$this->clientAdmitted((string)$current['client_id'], null, null)) {
                $disabled = true;
                return;
            }
            $lost = !$this->store->rotate((int)$row['id'], $oldHash, $this->hasher->hash($access), $now + self::ACCESS_TTL,
                $this->hasher->hash($refresh), $now + self::REFRESH_TTL);
        });
        if ($lost) {
            // Another request rotated this token first: a reuse, handled like any replay of a spent token.
            $this->replayed($oldHash, $get('client_id'));
        }
        if ($disabled) {
            throw new OAuthException('invalid_grant', 'This client is disabled');
        }
        if ($lost || $revoked) {
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        return $this->body($access, $refresh, (string)$row['scope']);
    }

    /**
     * Revokes every access and refresh token of the user, issued until now (an administrator's "revoke all"); the
     * user stays connected and a new consent works.
     *
     * @throws PolicyUnavailable when the policy storage fails
     */
    public function revokeUser(string $uid): void {
        $this->credentials->revokeCredentials($uid);
    }

    /**
     * Revokes one connection, under the issuance mutex.
     *
     * @param int $id grant id
     * @param string|null $uid required owner, null for an administrator
     * @return bool true when it existed (and, with $uid, belonged to that user)
     * @throws PolicyUnavailable when the policy storage fails
     */
    public function revokeGrant(int $id, ?string $uid): bool {
        return (bool)$this->transaction->underMutex(fn (): bool => $this->store->deleteGrant($id, $uid));
    }

    /**
     * Revokes every connection of one client, for all users, under the issuance mutex (native client switched off).
     *
     * @throws PolicyUnavailable when the policy storage fails
     */
    public function revokeClient(string $clientId): void {
        $this->transaction->underMutex(fn () => $this->store->deleteForClient($clientId));
    }

    /** Preflight of a token request: a bounded batch of pending cleanups and audit deliveries. Never throws. */
    public function housekeeping(): void {
        $this->events->flush(PolicyEventProcessor::PREFLIGHT_BATCH);
    }

    /**
     * Runs a unit for an owner who must still be usable (checked in the external phase, with the lifecycles of the
     * groups that admit the owner compared again under the mutex). An unusable owner is revoked by a cut at $observed,
     * the issue_seq read before the check, and the request fails with invalid_grant; an unknown eligibility fails
     * with PolicyUnavailable and revokes nothing.
     *
     * @param Closure(array, array):void $local the local statements, given the state row and generations under the mutex
     * @throws OAuthException invalid_grant for an unusable owner
     * @throws PolicyUnavailable when the storage fails or keeps changing
     */
    private function asOwner(string $uid, string $observed, \Closure $local): void {
        try {
            $this->credentials->runCredential($uid, function () use ($uid): array {
                return $this->gate->admission($uid, true) ?? throw new OwnerNotUsable();
            }, function (array $lifecycles, array $state, array $generations, PolicyAttempt $attempt) use ($local): bool {
                // The groups that admitted the owner must still grant (F3 of the review of ticket 07), and their gens are
                // renewed so a concurrent deletion's Before sees this issuance (F1 of the second reading).
                $this->holds->hold($attempt, $lifecycles);
                $local($state, $generations);
                return false;
            }, $this->gate->causes());
        } catch (OwnerNotUsable) {
            $this->gate->revokeObserved($uid, $observed);
            throw new OAuthException('invalid_grant', 'MCP access is not allowed for this account');
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            // An owner id the generation table refuses, or a digest collision: nothing can be issued for it.
            throw new PolicyUnavailable('Invalid credential owner', previous: $e);
        }
    }

    /**
     * Anti-replay of a spent refresh token: revokes every session of its owner and client (the 1.0.8 rule), but only
     * while the spent record is still valid. A record from before a cut or from an older epoch can no longer revoke
     * anything, so replaying it never removes the session of a later consent (contracts §3.2).
     *
     * @throws PolicyUnavailable when the storage fails
     */
    private function replayed(string $hash, string $clientId): void {
        $spent = $this->read(fn () => $this->store->findSpent($hash));
        if ($spent === null || $spent['client_id'] !== $clientId || (int)$spent['expires_at'] < $this->time->getTime()) {
            return;
        }
        $uid = (string)$spent['user_id'];
        try {
            $this->credentials->runCredential($uid, fn () => null, function ($snapshot, array $state, array $generations) use ($hash, $clientId, $uid): bool {
                $spent = $this->store->findSpent($hash);
                if ($spent !== null && $spent['client_id'] === $clientId && (int)$spent['expires_at'] >= $this->time->getTime()
                    && CredentialGeneration::live($spent, $generations, $state['auth_epoch'])) {
                    $this->store->deleteForOwnerAndClient($uid, $clientId);
                }
                return false;
            });
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid credential owner', previous: $e);
        }
    }

    /**
     * @template T
     * @param Closure(): T $read a read outside the mutex
     * @return T
     * @throws PolicyUnavailable when it fails
     */
    private function read(\Closure $read): mixed {
        try {
            return $read();
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the credential state', previous: $e);
        }
    }

    /** Re-read managed-client admission under the same mutex as withdrawal. */
    private function clientAdmitted(string $clientId, ?string $generation, ?string $redirectUri): bool {
        if ($clientId === NativeClient::CLIENT_ID) {
            $current = ClientResolver::nativeGeneration($this->clients);
            return $current !== null && ($generation === null || $generation === $current);
        }
        return !CustomClient::owns($clientId) || CustomClient::admits($this->clients, $clientId, $generation, $redirectUri);
    }

    /**
     * Stores a new grant of the lineage's generation; runs under the mutex, in the transaction that consumed its code.
     *
     * @return array<string,string|int>
     */
    private function issue(string $uid, string $clientId, string $resource, string $scope, string $generation, string $epoch): array {
        $access = $this->hasher->generate('at');
        $refresh = $this->hasher->generate('rt');
        $now = $this->time->getTime();
        $this->store->insertToken([
            'user_id' => $uid,
            'client_id' => $clientId,
            'resource' => $resource,
            'scope' => $scope,
            'access_hash' => $this->hasher->hash($access),
            'access_expires' => $now + self::ACCESS_TTL,
            'refresh_hash' => $this->hasher->hash($refresh),
            'refresh_expires' => $now + self::REFRESH_TTL,
            'created_at' => $now,
            'auth_global_epoch' => $epoch,
            'auth_gen' => $generation,
        ], $now);
        return $this->body($access, $refresh, $scope);
    }

    /** @return array<string,string|int> */
    private function body(string $access, string $refresh, string $scope): array {
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }
}
