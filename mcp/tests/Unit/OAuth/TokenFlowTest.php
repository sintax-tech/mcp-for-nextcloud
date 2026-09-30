<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\ClientMetadata;
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
    private AccessTokenAuthenticator $authenticator;

    protected function setUp(): void {
        $this->store = new InMemoryOAuthStore();
        $this->policy = new GrantPolicy((new InMemoryConfig())->mock($this));
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
        $alice->method('isEnabled')->willReturn(true);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
        $gate = new TokenOwnerGate($users, $this->policy, $this->store);
        $this->service = new TokenService($this->store, $hasher, $time, $gate);
        $this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate);
    }

    private function code(): string {
        $request = new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]),
            self::REDIRECT, 'xyz', self::CHALLENGE, self::RESOURCE, 'mcp');
        return $this->service->createCode($request, 'alice');
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
        $dump = json_encode([$this->store->codes, $this->store->tokens]);
        foreach ([$code, $tokens['access_token'], $tokens['refresh_token']] as $secret) {
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
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $access, 'https://other.example.org/apps/mcp/'), 'wrong audience');
        $this->assertNull($this->authenticator->authenticate('Bearer ncmcp_at_unknown', self::RESOURCE));
        $this->now += TokenService::ACCESS_TTL + 1;
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $access, self::RESOURCE), 'expired');
    }

    public function testRefreshRotatesAndOldTokensStopWorking(): void {
        $first = $this->exchange($this->code());
        $second = $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $first['access_token'], self::RESOURCE));
        $this->assertNotNull($this->authenticator->authenticate('Bearer ' . $second['access_token'], self::RESOURCE));
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testLosingEligibilityRevokesAccessAndRefresh(): void {
        $tokens = $this->exchange($this->code());
        $this->policy->setEligible('alice', false);
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
        $this->assertSame([], $this->store->tokens);
        $this->policy->setEligible('alice', true);
        $this->assertGrantFails(fn () => $this->service->refresh(['refresh_token' => $tokens['refresh_token'], 'client_id' => self::CLIENT]));
    }

    public function testDisconnectRevokesEverything(): void {
        $tokens = $this->exchange($this->code());
        $this->service->revokeUser('alice');
        $this->assertNull($this->authenticator->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE));
    }
}
