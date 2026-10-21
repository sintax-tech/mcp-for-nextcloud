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
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use Psr\Log\NullLogger;
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
    private ClientsTable $clients;
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
        $services = InMemoryConfig::services($appConfigMock, $this->store);
        $gate = new TokenOwnerGate($users, $this->policy, $services->credentials, $this->createMock(IUserConfig::class),
            $this->createMock(IAppConfig::class), new NullLogger(), InMemoryConfig::noCauses($this));
        $this->clients = new ClientsTable($this);
        $this->service = new TokenService($this->store, $hasher, $time, $gate, $services->credentials,
            InMemoryConfig::authUsers($appConfigMock), InMemoryConfig::processor($appConfigMock), $this->clients->mapper, $services->transaction, $services->holds);
        $this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate,
            InMemoryConfig::authUsers($appConfigMock), InMemoryConfig::state($appConfigMock), $this->clients->mapper);
    }

    private function code(): string {
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]),
            self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->consent($request, 'alice');
    }

    private function nativeCode(): string {
        $request = new AuthorizationRequest(NativeClient::metadata(), 'http://localhost:53421/oauth/callback',
            'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->consent($request, 'alice');
    }

    private function nativeExchange(string $code): array {
        return $this->service->exchangeCode([
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => NativeClient::CLIENT_ID, 'redirect_uri' => 'http://localhost:53421/oauth/callback',
            'resource' => self::RESOURCE,
        ]);
    }

    public function testNativeClientExchangesAndRefreshesWhileEnabled(): void {
        $this->clients->native(true);
        $tokens = $this->nativeExchange($this->nativeCode());
        $next = $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => NativeClient::CLIENT_ID]);
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
    }

    public function testDisabledNativeClientCannotExchangeAnIssuedCode(): void {
        $this->clients->native(true);
        $code = $this->nativeCode();
        $this->clients->native(false);
        $this->assertGrantFails(fn () => $this->nativeExchange($code));
    }

    public function testDisabledNativeClientCannotRefresh(): void {
        $this->clients->native(true);
        $tokens = $this->nativeExchange($this->nativeCode());
        $this->clients->native(false);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => NativeClient::CLIENT_ID]));
    }

    public function testDisabledNativeClientAccessTokenStopsAuthenticating(): void {
        $this->clients->native(true);
        $native = $this->nativeExchange($this->nativeCode());
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $native['access_token'], self::RESOURCE));
        $this->clients->native(false);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $native['access_token'], self::RESOURCE));
    }

    public function testDisabledNativeClientDoesNotAffectCimdTokens(): void {
        $tokens = $this->exchange($this->code());
        $this->clients->native(false);
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }

    private const CUSTOM_REDIRECT = 'https://harness.example.com/oauth/callback';
    private const CUSTOM_A = 'nextcloud-mcp-custom-aaaaaaaaaaaa';
    private const CUSTOM_B = 'nextcloud-mcp-custom-bbbbbbbbbbbb';

    /** Stores self-hosted clients A and B, each with its own redirect URI, and the Enabled setting of each. */
    private function customClients(bool $a = true, bool $b = true): void {
        $this->clients->custom([
            ['id' => 'aaaaaaaaaaaa', 'name' => 'QwenPaw', 'enabled' => $a, 'redirectUris' => [self::CUSTOM_REDIRECT]],
            ['id' => 'bbbbbbbbbbbb', 'name' => 'Other', 'enabled' => $b, 'redirectUris' => ['https://other.example.org/cb']],
        ]);
    }

    private function customCode(string $clientId = self::CUSTOM_A, string $redirect = self::CUSTOM_REDIRECT): string {
        $request = new AuthorizationRequest(new ClientMetadata($clientId, 'QwenPaw', [$redirect]),
            $redirect, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->consent($request, 'alice');
    }

    private function customExchange(string $code, string $redirect = self::CUSTOM_REDIRECT, string $clientId = self::CUSTOM_A): array {
        return $this->service->exchangeCode([
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => $clientId, 'redirect_uri' => $redirect, 'resource' => self::RESOURCE,
        ]);
    }

    public function testSelfHostedClientExchangesRefreshesAndAuthenticatesWhileEnabled(): void {
        $this->customClients();
        $tokens = $this->customExchange($this->customCode());
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $next = $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CUSTOM_A]);
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
    }

    public function testSelfHostedCodeIsBoundToItsRedirectUriAndItsClient(): void {
        $this->customClients();
        $this->assertGrantFails(fn () => $this->customExchange($this->customCode(), self::CUSTOM_REDIRECT . '/'));
        $this->assertGrantFails(fn () => $this->customExchange($this->customCode(), self::CUSTOM_REDIRECT, self::CUSTOM_B));
    }

    public function testDisabledSelfHostedClientCannotExchangeRefreshOrAuthenticateAndTheOtherOneStillWorks(): void {
        $this->customClients();
        $code = $this->customCode();
        $tokens = $this->customExchange($this->customCode());
        $other = $this->customExchange($this->customCode(self::CUSTOM_B, 'https://other.example.org/cb'), 'https://other.example.org/cb', self::CUSTOM_B);
        $this->customClients(false, true);
        $this->assertGrantFails(fn () => $this->customExchange($code));
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CUSTOM_A]));
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $other['access_token'], self::RESOURCE));
    }

    public function testDeletedSelfHostedClientCannotRefreshOrAuthenticate(): void {
        $this->customClients();
        $tokens = $this->customExchange($this->customCode());
        $this->clients->custom([]);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CUSTOM_A]));
    }

    public function testDisabledSelfHostedClientDoesNotAffectCimdOrNativeTokens(): void {
        $this->clients->native(true);
        $native = $this->nativeExchange($this->nativeCode());
        $cimd = $this->exchange($this->code());
        $this->customClients(false, false);
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $native['access_token'], self::RESOURCE));
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $cimd['access_token'], self::RESOURCE));
    }

    public function testDeleteForClientRemovesOnlyThatClientsRows(): void {
        $this->clients->native(true);
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

    public function testAReplayAfterTheHistoryExpiredRevokesNothing(): void {
        $first = $this->exchange($this->code());
        $current = $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        foreach ($this->store->spent as &$row) {
            $row['expires_at'] = $this->now - 1;
        }
        unset($row);
        $before = $this->store->tokens;
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
        $this->assertSame($before, $this->store->tokens);
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $current['access_token'], self::RESOURCE));
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
