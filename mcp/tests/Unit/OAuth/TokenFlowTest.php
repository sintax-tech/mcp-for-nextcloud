<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class TokenFlowTest extends TestCase {
    private const CLIENT = 'https://claude.ai/oauth/mcp-client-metadata';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const RESOURCE = 'https://cloud.example.org/apps/mcp/';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private InMemoryOAuthStore $store;
    private GrantPolicy $policy;
    private int $now = 1_000_000;
    private TokenService $service;
    private InMemoryConfig $appConfig;
    private AccessTokenAuthenticator $authenticator;
    private bool $aliceEnabled = true;
    private bool $aliceExists = true;

    protected function setUp(): void {
        $this->store = new InMemoryOAuthStore();
        $this->appConfig = new InMemoryConfig();
        $appConfigMock = $this->appConfig->mock($this);
        $this->policy = \OCA\Mcp\Tests\Unit\InMemoryConfig::policy($appConfigMock, $this->store);
        $this->policy->setGlobalEnabled(true);
        $this->policy->setEligible('alice', true);
        $this->policy->setConnected('alice', true);
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturn('instance-secret');
        $hasher = new TokenHasher($config);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn () => $this->now);
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturnCallback(fn (): bool => $this->aliceEnabled);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' && $this->aliceExists ? $alice : null);
        $gate = new TokenOwnerGate($users, $this->policy, $this->store);
        $this->service = new TokenService($this->store, $hasher, $time, $gate, $appConfigMock);
        $this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate, $appConfigMock);
    }

    private function code(): string {
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]),
            self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->createCode($request, 'alice');
    }

    private function nativeCode(): string {
        $request = new AuthorizationRequest(NativeClient::metadata(), 'http://localhost:53421/oauth/callback',
            'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->createCode($request, 'alice');
    }

    private function nativeExchange(string $code): array {
        return $this->service->exchangeCode([
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => NativeClient::CLIENT_ID, 'redirect_uri' => 'http://localhost:53421/oauth/callback',
            'resource' => self::RESOURCE,
        ]);
    }

    public function testNativeClientExchangesAndRefreshesWhileEnabled(): void {
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $tokens = $this->nativeExchange($this->nativeCode());
        $next = $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => NativeClient::CLIENT_ID]);
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
    }

    public function testDisabledNativeClientCannotExchangeAnIssuedCode(): void {
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $code = $this->nativeCode();
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '0';
        $this->assertGrantFails(fn () => $this->nativeExchange($code));
    }

    public function testDisabledNativeClientCannotRefresh(): void {
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $tokens = $this->nativeExchange($this->nativeCode());
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '0';
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => NativeClient::CLIENT_ID]));
    }

    public function testDisabledNativeClientAccessTokenStopsAuthenticating(): void {
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $native = $this->nativeExchange($this->nativeCode());
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $native['access_token'], self::RESOURCE));
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '0';
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $native['access_token'], self::RESOURCE));
    }

    public function testDisabledNativeClientDoesNotAffectCimdTokens(): void {
        $tokens = $this->exchange($this->code());
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '0';
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }

    public function testDeleteForClientRemovesOnlyThatClientsRows(): void {
        $this->appConfig->app['mcp'][NativeClient::CONFIG_KEY] = '1';
        $this->nativeExchange($this->nativeCode());
        $this->nativeCode();
        $this->exchange($this->code());
        $this->store->spent['x'] = ['client_id' => NativeClient::CLIENT_ID, 'id' => 99];
        $this->store->spent['y'] = ['client_id' => self::CLIENT, 'id' => 98];
        $this->store->deleteForClient(NativeClient::CLIENT_ID);
        $this->assertSame([self::CLIENT], array_values(array_column($this->store->tokens, 'client_id')));
        $this->assertSame([], $this->store->codes);
        $this->assertSame(['y'], array_keys($this->store->spent));
    }

    private function exchange(string $code, array $override = []): array {
        return $this->service->exchangeCode($override + [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'resource' => self::RESOURCE,
        ]);
    }

    private function assertGrantFails(callable $action): void {
        try {
            $action();
            $this->fail('invalid_grant expected');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
    }

    public function testOnlyHashesArePersisted(): void {
        $code = $this->code();
        $tokens = $this->exchange($code);
        $rotated = $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertArrayHasKey(hash_hmac('sha256', $tokens['refresh_token'], 'mcp-oauth|instance-secret'), $this->store->spent);
        $dump = json_encode([$this->store->codes, $this->store->tokens, $this->store->spent]);
        foreach ([$code, $tokens['access_token'], $tokens['refresh_token'], $rotated['access_token'], $rotated['refresh_token']] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertStringStartsWith('ncmcp_at_', $tokens['access_token']);
        $this->assertSame(TokenService::ACCESS_TTL, $tokens['expires_in']);
    }

    public function testCodeIsSingleUseAndBoundToPkceClientAndRedirect(): void {
        $this->assertGrantFails(fn () => $this->exchange($this->code(), ['code_verifier' => str_repeat('a', 43)]));
        $this->assertGrantFails(fn () => $this->exchange($this->code(), ['redirect_uri' => self::REDIRECT . '/']));
        $this->assertGrantFails(fn () => $this->exchange($this->code(), ['client_id' => 'https://claude.ai/other']));
        $code = $this->code();
        $this->exchange($code);
        $this->assertGrantFails(fn () => $this->exchange($code));
    }

    public function testExpiredCodeFails(): void {
        $code = $this->code();
        $this->now += TokenService::CODE_TTL + 1;
        $this->assertGrantFails(fn () => $this->exchange($code));
    }

    public function testBearerResolvesToOwnerUntilExpiry(): void {
        $access = $this->exchange($this->code())['access_token'];
        $this->assertSame('alice', $this->authenticator->authenticate('Bearer ' . $access, 'https://cloud.example.org/index.php/apps/mcp/')?->getUID());
        $this->assertSame('alice', $this->authenticator->authenticate('bEaReR  ' . $access, self::RESOURCE)?->getUID());
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $access, 'https://other.example.org/apps/mcp/'), 'wrong audience');
        $this->assertNull($this->authenticator->authenticate('Bearer ncmcp_at_unknown', self::RESOURCE));
        $this->now += TokenService::ACCESS_TTL + 1;
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $access, self::RESOURCE), 'expired');
    }

    public function testOfflineOnlyBearerCannotAccessMcp(): void {
        $tokens = $this->exchange($this->code());
        $id = array_key_first($this->store->tokens);
        $this->store->tokens[$id]['scope'] = 'offline_access';
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }

    public function testRefreshRotatesAndOldTokensStopWorking(): void {
        $first = $this->exchange($this->code());
        $second = $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $first['access_token'], self::RESOURCE));
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $second['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testRefreshReplayRevokesAllGrantsForTheClientAndOwner(): void {
        $first = $this->exchange($this->code());
        $otherGrant = $this->exchange($this->code());
        $second = $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $third = $this->service->refresh(['refresh_token' => $second['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $third['access_token'], self::RESOURCE));
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $otherGrant['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $third['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testLosingRefreshRotationRaceRevokesTheWinningGrant(): void {
        $first = $this->exchange($this->code());
        // The store publishes the winning rotation but reports that this request lost its conditional update.
        $this->store->loseRotationRace = true;
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
        $this->assertSame([], $this->store->tokens);
    }

    public function testReplayDoesNotRevokeOtherClientsOrUsers(): void {
        $first = $this->exchange($this->code());
        $this->store->insertToken(['user_id' => 'bob', 'client_id' => self::CLIENT], $this->now);
        $this->store->insertToken(['user_id' => 'alice', 'client_id' => 'https://other.example/client'], $this->now);
        $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
        $this->assertSame(['bob', 'alice'], array_values(array_column($this->store->tokens, 'user_id')));
    }

    public function testRefreshReplayWithWrongClientDoesNotRevokeTheOwner(): void {
        $first = $this->exchange($this->code());
        $second = $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => 'https://other.example/client']));
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $second['access_token'], self::RESOURCE));
    }

    public function testLosingEligibilityRevokesAccessAndRefresh(): void {
        $tokens = $this->exchange($this->code());
        $this->policy->setEligible('alice', false);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertSame([], $this->store->tokens);
        $this->policy->setEligible('alice', true);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testServiceOffThenOnPermanentlyRevokesUnusedCredentials(): void {
        $tokens = $this->exchange($this->code());
        $code = $this->code();
        $this->policy->setGlobalEnabled(false);
        $this->assertSame([], $this->store->tokens);
        $this->assertSame([], $this->store->codes);
        $this->policy->setGlobalEnabled(true);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->exchange($code));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testEligibilityRemovalThenRestorationPermanentlyRevokesUnusedCredentials(): void {
        $tokens = $this->exchange($this->code());
        $code = $this->code();
        $this->policy->setEligible('alice', false);
        $this->assertSame([], $this->store->tokens);
        $this->assertSame([], $this->store->codes);
        $this->policy->setEligible('alice', true);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->exchange($code));
    }

    public function testDisconnectRevokesEverything(): void {
        $tokens = $this->exchange($this->code());
        $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertNotEmpty($this->store->spent);
        $this->service->revokeUser('alice');
        $this->assertSame([], $this->store->spent);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }

    /** A refresh token that is still alive is not a bearer credential for whoever holds it: it belongs to one client. */
    public function testALiveRefreshTokenIsRefusedToAnotherClientAndStaysUsableByItsOwn(): void {
        $tokens = $this->exchange($this->code());

        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => 'https://other.example/client']));

        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE), 'the theft attempt did not revoke the grant');
        $next = $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
    }

    public function testARefreshTokenExpiresAfterItsLifetime(): void {
        $tokens = $this->exchange($this->code());

        $this->now += TokenService::REFRESH_TTL;
        $this->assertNotEmpty($this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]), 'still alive on the last second');

        $second = $this->exchange($this->code());
        $this->now += TokenService::REFRESH_TTL + 1;
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $second['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testACodeIsRefusedForAResourceOfAnotherServer(): void {
        $this->assertGrantFails(fn () => $this->exchange($this->code(), ['resource' => 'https://other.example.org/apps/mcp/']));
    }

    public function testACodeExchangedWithoutAResourceKeepsTheOneItWasIssuedFor(): void {
        $tokens = $this->exchange($this->code(), ['resource' => '']);

        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], 'https://other.example.org/apps/mcp/'));
    }

    /**
     * The gate is what stops a token the moment its owner is no longer allowed, without anything having
     * revoked it first: GrantPolicy::setConnected(false) keeps the rows, so only the gate refuses them.
     */
    public function testAPersonalDisconnectStopsTheBearerThroughTheGate(): void {
        $tokens = $this->exchange($this->code());
        $this->policy->setConnected('alice', false);

        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));

        $this->assertSame([], $this->store->tokens, 'the gate deleted the grants, so reconnecting needs a new consent');
        $this->policy->setConnected('alice', true);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }

    public function testADisabledAccountStopsTheBearerAndTheRefresh(): void {
        $tokens = $this->exchange($this->code());
        $this->aliceEnabled = false;

        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertSame([], $this->store->tokens);
        $this->aliceEnabled = true;
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testARemovedAccountStopsTheBearer(): void {
        $tokens = $this->exchange($this->code());
        $this->aliceExists = false;

        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertSame([], $this->store->tokens);
    }

    public function testADisabledAccountCannotExchangeAnIssuedCode(): void {
        $code = $this->code();
        $this->aliceEnabled = false;

        $this->assertGrantFails(fn () => $this->exchange($code));
        $this->assertSame([], $this->store->tokens);
    }
}
