<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\BackgroundJob\PolicyCleanupJob;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Server;
use RuntimeException;

/**
 * Ticket 04 on the real server: the cut by generation through the production services, the consent as one
 * transaction with the official config, the account listener, and the cleanup job registered by the app. The global
 * epoch runs last, in Last/GlobalEpochTest.php, after the baseline credentials of the upgrade cells are checked.
 */
final class GenerationCutTest extends OAuthAccountTestCase {
    public function testTheLineageKeepsItsGenerationAndADisconnectCutsEverythingIssuedBefore(): void {
        $second = $this->openIndependentConnection();
        $first = $this->exchange($this->consent());
        $next = Server::get(TokenService::class)->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
        $other = $this->exchange($this->consent());
        $e = $this->epoch;
        $this->assertSame([[$e, '2'], [$e, '3']], $this->stamps($second, 'mcp_oauth_tokens'));
        $this->assertSame([[$e, '2']], $this->stamps($second, 'mcp_oauth_spent'), 'the spent record keeps the generation of its lineage');
        $this->assertSame([$this->uid, $this->uid], [$this->bearer($next), $this->bearer($other)], 'a consent leaves the other sessions alone');

        Server::get(GrantPolicy::class)->setConnected($this->uid, false);
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_tokens'), 'the cleanup after the commit removed them');
        $this->assertNull($this->bearer($other));

        $fresh = $this->exchange($this->consent());
        Server::get(OAuthStore::class)->deleteUpToGeneration($this->uid, '3');
        $this->assertSame($this->uid, $this->bearer($fresh), 'a repeated cleanup never removes a later generation');
        try {
            Server::get(TokenService::class)->refresh(['refresh_token' => $first['refresh_token'], 'client_id' => self::CLIENT]);
            $this->fail('A refresh token spent before the cut was accepted');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
        $this->assertSame($this->uid, $this->bearer($fresh), 'its replay cannot revoke the session of the later consent');
    }

    /** Connected and the code are one transaction with the official config: a failed code leaves neither. */
    public function testAConsentWhoseCodeFailsLeavesTheUserDisconnectedOnEverySession(): void {
        $second = $this->openIndependentConnection();
        $state = Server::get(PolicyStateMapper::class)->state();
        $failing = new class($this->official()) extends OAuthStore {
            public function insertCode(array $row, int $now): void {
                parent::insertCode($row, $now);
                throw new RuntimeException('Injected after the code');
            }
        };
        $tokens = new TokenService($failing, Server::get(TokenHasher::class), Server::get(ITimeFactory::class), Server::get(TokenOwnerGate::class), Server::get(\OCA\Mcp\OAuth\Revocation\CredentialCommands::class), Server::get(AuthUsersMapper::class), Server::get(PolicyEventProcessor::class), Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class), Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class), Server::get(\OCA\Mcp\Access\Grants\Groups\LifecycleHolds::class));
        try {
            $tokens->consent($this->request(), $this->uid);
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected after the code', $e->getMessage());
        }
        $this->assertFalse($this->official()->inTransaction());
        $users = clone Server::get(IUserConfig::class);
        (new \ReflectionProperty($users, 'connection'))->setValue($users, $second);
        $users->clearCache($this->uid, false);
        $this->assertSame([], $users->getValuesByUsers('mcp', GrantPolicy::CONNECTED_KEY, null, [$this->uid]));
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
        $this->assertFalse(Server::get(GrantPolicy::class)->connected($this->uid), 'the worker cache was not left with the rolled back value');
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->assertSame([], $this->stamps($second, 'mcp_oauth_codes'));
        $this->assertSame($state, (new PolicyStateMapper($second))->state());
    }

    /** Disabling the account in Nextcloud cuts through the app's listener, in the same request. */
    public function testDisablingTheAccountCutsItsCredentials(): void {
        $second = $this->openIndependentConnection();
        $grant = $this->exchange($this->consent());
        $this->user->setEnabled(false);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], (new AuthUsersMapper($second))->generations($this->uid));
        $this->user->setEnabled(true);
        $this->assertNull($this->bearer($grant));
        $this->assertSame($this->uid, $this->bearer($this->exchange($this->consent())));
    }

    /** Registered from appinfo/info.xml by install and upgrade alike, and safe to run at any time. */
    public function testTheDailyCleanupJobIsRegisteredByTheAppAndRuns(): void {
        $this->assertTrue(Server::get(IJobList::class)->has(PolicyCleanupJob::class, null));
        $job = Server::get(PolicyCleanupJob::class);
        $this->assertSame(PolicyCleanupJob::INTERVAL, $job->getInterval());
        $grant = $this->exchange($this->consent());
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
        $this->assertSame($this->uid, $this->bearer($grant), 'the job never removes a valid credential');
    }
}
