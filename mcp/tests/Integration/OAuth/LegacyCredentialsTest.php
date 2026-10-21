<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

/**
 * T12 on the real upgrade from the released baseline: the code, tokens and spent record that release issued (by
 * ci/legacy-credentials.php, before `occ upgrade`) keep every column and hash, carry epoch 0 and generation 1, and
 * still work through the current token service and authenticator.
 */
final class LegacyCredentialsTest extends IntegrationTestCase {
    private ?array $legacy = null;

    protected function setUp(): void {
        parent::setUp();
        if (!in_array(getenv('MCP_IT_MODE'), ['upgrade', 'upgrade-local'], true)) {
            $this->markTestSkipped('Only an upgrade cell has credentials issued by the baseline');
        }
        $path = (string)getenv('MCP_IT_LEGACY');
        $this->assertFileExists($path, 'The upgrade step must have issued the baseline credentials');
        $this->legacy = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void {
        if ($this->legacy !== null) {
            Server::get(OAuthStore::class)->deleteUpToGeneration(Fixtures::LEGACY, \OCA\Mcp\Access\Policy\DecimalCounter::MAX);
            $config = Server::get(IConfig::class);
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY] as $key) {
                $config->deleteUserValue(Fixtures::LEGACY, 'mcp', $key);
            }
            $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
            Server::get(IUserConfig::class)->clearCache(Fixtures::LEGACY, false);
            Server::get(IAppConfig::class)->clearCache(false);
        }
        parent::tearDown();
    }

    /** @return list<array<string, string>> the owner's rows of one OAuth table, every value as a string */
    private function rows(string $table): array {
        $qb = $this->official()->getQueryBuilder();
        $result = $qb->select('*')->from($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter(Fixtures::LEGACY)))->executeQuery();
        $rows = array_map(static fn (array $row): array => array_map('strval', $row), $result->fetchAll());
        $result->closeCursor();
        return $rows;
    }

    public function testCredentialsIssuedByTheBaselineSurviveTheUpgradeAndStillWork(): void {
        $legacy = $this->legacy;
        $this->assertSame(Fixtures::LEGACY, $legacy['uid']);
        $this->assertCount(1, $legacy['rows']['mcp_oauth_codes']);
        $this->assertCount(2, $legacy['rows']['mcp_oauth_tokens']);
        $this->assertCount(1, $legacy['rows']['mcp_oauth_spent']);
        foreach ($legacy['rows'] as $table => $before) {
            $after = $this->rows($table);
            $this->assertCount(count($before), $after, $table);
            $key = $table === 'mcp_oauth_spent' ? 'refresh_hash' : 'id';
            $after = array_column($after, null, $key);
            foreach ($before as $row) {
                $now = $after[$row[$key]] ?? null;
                $this->assertNotNull($now, "$table row {$row[$key]} was recreated or lost");
                // Same columns, ids and hashes, plus the two generation columns at their migrated values.
                $this->assertSame($row, array_intersect_key($now, $row), $table);
                $this->assertSame(['auth_global_epoch' => '0', 'auth_gen' => '1'], array_diff_key($now, $row), $table);
            }
        }

        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(Fixtures::LEGACY, true);
        $policy->setConnected(Fixtures::LEGACY, true);
        $authenticator = Server::get(AccessTokenAuthenticator::class);
        $tokens = Server::get(TokenService::class);
        $bearer = fn (string $token) => $authenticator->authenticate('Bearer ' . $token, $legacy['resource'])?->getUID();

        $this->assertSame(Fixtures::LEGACY, $bearer($legacy['tokens']['rotated']['access']));
        $this->assertSame(Fixtures::LEGACY, $bearer($legacy['tokens']['plain']['access']));
        $exchanged = $tokens->exchangeCode(['code' => $legacy['code'], 'code_verifier' => $legacy['verifier'],
            'client_id' => $legacy['client'], 'redirect_uri' => $legacy['redirect'], 'resource' => $legacy['resource']]);
        $this->assertSame(Fixtures::LEGACY, $bearer((string)$exchanged['access_token']));
        $refreshed = $tokens->refresh(['refresh_token' => $legacy['tokens']['plain']['refresh'], 'client_id' => $legacy['client']]);
        $this->assertSame(Fixtures::LEGACY, $bearer((string)$refreshed['access_token']));
        $this->assertNull($bearer($legacy['tokens']['plain']['access']), 'rotation replaced the old access token');

        // The refresh token the baseline already spent is still recognised as a replay, and revokes that client.
        try {
            $tokens->refresh(['refresh_token' => $legacy['spent'], 'client_id' => $legacy['client']]);
            $this->fail('A spent refresh token was accepted');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
        $this->assertNull($bearer($legacy['tokens']['rotated']['access']));
        $this->assertNull($bearer((string)$refreshed['access_token']));
        $this->assertSame([], $this->rows('mcp_oauth_tokens'));
    }
}
