<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/**
 * An account of its own for each OAuth test on the real server, created and deleted here, with the service on and the
 * account eligible; the global auth epoch and the service switch are put back afterwards.
 */
abstract class OAuthAccountTestCase extends IntegrationTestCase {
    protected const CLIENT = 'https://claude.ai/oauth/mcp-client-metadata';
    protected const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    protected const RESOURCE = 'https://cloud.example.org/apps/mcp/';
    protected const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    protected const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected string $uid = '';
    protected ?IUser $user = null;
    protected string $epoch = '0';
    private ?string $service = null;

    protected function setUp(): void {
        parent::setUp();
        $this->uid = 'mcp-it-gen-' . bin2hex(random_bytes(6));
        $this->user = Server::get(IUserManager::class)->createUser($this->uid, bin2hex(random_bytes(24))) ?: null;
        $this->assertInstanceOf(IUser::class, $this->user);
        $this->epoch = Server::get(PolicyStateMapper::class)->state()['auth_epoch'];
        Server::get(\OCP\IAppConfig::class)->clearCache(false);
        $this->service = Server::get(\OCP\IAppConfig::class)->hasKey('mcp', GrantPolicy::SERVICE_KEY)
            ? Server::get(IConfig::class)->getAppValue('mcp', GrantPolicy::SERVICE_KEY) : null;
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible($this->uid, true);
    }

    protected function tearDown(): void {
        $this->user?->delete();
        $db = $this->official();
        foreach (['mcp_auth_users', 'mcp_auth_recheck'] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($this->uid))))->executeStatement();
        }
        Server::get(OAuthStore::class)->deleteUpToGeneration($this->uid, DecimalCounter::MAX);
        // Test-only reset of the app's own counter, so later tests (and the baseline credentials) keep their epoch.
        $qb = $db->getQueryBuilder();
        $qb->update('mcp_policy_state')->set('auth_epoch', $qb->createNamedParameter($this->epoch))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1)))->executeStatement();
        $config = Server::get(IConfig::class);
        $this->service === null ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
        Server::get(\OCP\IAppConfig::class)->clearCache(false);
        parent::tearDown();
    }

    protected function request(): AuthorizationRequest {
        return new AuthorizationRequest(new ClientMetadata(self::CLIENT, 'Claude', [self::REDIRECT]), self::REDIRECT, 'xyz',
            self::CHALLENGE, self::RESOURCE, 'mcp');
    }

    protected function consent(?TokenService $tokens = null): string {
        $code = ($tokens ?? Server::get(TokenService::class))->consent($this->request(), $this->uid);
        $this->assertIsString($code);
        return $code;
    }

    /** @return array<string, string|int> */
    protected function exchange(string $code): array {
        return Server::get(TokenService::class)->exchangeCode(['code' => $code, 'code_verifier' => self::VERIFIER,
            'client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'resource' => self::RESOURCE]);
    }

    protected function bearer(array $tokens): ?string {
        return Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $tokens['access_token'], self::RESOURCE)?->getUID();
    }

    /** @return list<array{string, string}> (auth_global_epoch, auth_gen) of the user's rows in one table, as another session reads them */
    protected function stamps(IDBConnection $db, string $table): array {
        $qb = $db->getQueryBuilder();
        $result = $qb->select('auth_global_epoch', 'auth_gen')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->uid)))
            ->orderBy('auth_gen')->executeQuery();
        $rows = array_map(static fn (array $row): array => [(string)$row['auth_global_epoch'], (string)$row['auth_gen']], $result->fetchAll());
        $result->closeCursor();
        return $rows;
    }
}
