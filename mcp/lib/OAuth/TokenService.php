<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Issues authorization codes and token grants and runs the token endpoint grants (authorization_code with PKCE,
 * refresh_token with rotation). Codes and refresh tokens are single use; only their hashes are stored.
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
        private IConfig $config,
    ) {}

    /**
     * @param AuthorizationRequest $request the consented request
     * @param string $uid the consenting user
     * @return string the one-time authorization code
     */
    public function createCode(AuthorizationRequest $request, string $uid): string {
        $code = $this->hasher->generate('code');
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
        ], $now);
        return $code;
    }

    /**
     * @param array<string,mixed> $params form parameters of the token request (grant_type=authorization_code)
     * @return array<string,string|int> the token response body
     * @throws OAuthException invalid_request / invalid_grant
     */
    public function exchangeCode(array $params): array {
        $get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
        if ($get('code') === '' || $get('code_verifier') === '' || $get('client_id') === '') {
            throw new OAuthException('invalid_request', 'code, code_verifier and client_id are required');
        }
        $row = $this->store->consumeCode($this->hasher->hash($get('code')));
        if ($row === null || (int)$row['expires_at'] < $this->time->getTime()
            || $row['client_id'] !== $get('client_id') || $row['redirect_uri'] !== $get('redirect_uri')
            || !Pkce::verify($get('code_verifier'), (string)$row['code_challenge'])
            || ($get('resource') !== '' && !ResourceUrl::sameResource($get('resource'), (string)$row['resource']))) {
            throw new OAuthException('invalid_grant', 'Invalid authorization code');
        }
        $this->assertClientEnabled((string)$row['client_id']);
        if ($this->gate->owner((string)$row['user_id']) === null) {
            throw new OAuthException('invalid_grant', 'MCP access is not allowed for this account');
        }
        return $this->issue((string)$row['user_id'], (string)$row['client_id'], (string)$row['resource'], (string)$row['scope']);
    }

    /**
     * @param array<string,mixed> $params form parameters of the token request (grant_type=refresh_token)
     * @return array<string,string|int> the token response body with a new refresh token
     * @throws OAuthException invalid_request / invalid_grant
     */
    public function refresh(array $params): array {
        $get = static fn (string $name): string => is_string($params[$name] ?? null) ? $params[$name] : '';
        if ($get('refresh_token') === '' || $get('client_id') === '') {
            throw new OAuthException('invalid_request', 'refresh_token and client_id are required');
        }
        $oldHash = $this->hasher->hash($get('refresh_token'));
        $row = $this->store->findByRefresh($oldHash);
        $now = $this->time->getTime();
        if ($row === null) {
            $this->store->revokeReusedRefresh($oldHash, $get('client_id'), $now);
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        if ((int)$row['refresh_expires'] < $now || $row['client_id'] !== $get('client_id')) {
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        $this->assertClientEnabled((string)$row['client_id']);
        if ($this->gate->owner((string)$row['user_id']) === null) {
            throw new OAuthException('invalid_grant', 'MCP access is not allowed for this account');
        }
        $access = $this->hasher->generate('at');
        $refresh = $this->hasher->generate('rt');
        if (!$this->store->rotate((int)$row['id'], $oldHash, $this->hasher->hash($access), $now + self::ACCESS_TTL,
            $this->hasher->hash($refresh), $now + self::REFRESH_TTL)) {
            $this->store->revokeReusedRefresh($oldHash, $get('client_id'), $now);
            throw new OAuthException('invalid_grant', 'Invalid refresh token');
        }
        return $this->body($access, $refresh, (string)$row['scope']);
    }

    /**
     * @param string $clientId client of a grant being exchanged or renewed
     * @throws OAuthException invalid_grant when the grant belongs to the native client and it is disabled
     */
    private function assertClientEnabled(string $clientId): void {
        if ($clientId === NativeClient::CLIENT_ID && !ClientResolver::nativeEnabled($this->config)) {
            throw new OAuthException('invalid_grant', 'This client is disabled');
        }
    }

    /** Revokes every access and refresh token of the user (personal Disconnect). */
    public function revokeUser(string $uid): void {
        $this->store->deleteForUser($uid);
    }

    /** @return array<string,string|int> */
    private function issue(string $uid, string $clientId, string $resource, string $scope): array {
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
