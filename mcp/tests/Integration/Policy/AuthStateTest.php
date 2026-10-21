<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use RuntimeException;

/**
 * T18 on the real databases: the B2b tables and OAuth generation columns exist after fresh install and upgrade, take
 * their defaults, and the composition mappers commit or roll back with the policy writer, seen from a second session.
 */
final class AuthStateTest extends IntegrationTestCase {
    private string $uid = '';
    private string $gid = '';

    protected function setUp(): void {
        parent::setUp();
        // Generation rows are tombstones that are never deleted: each run uses ids nothing else will ever use.
        $this->uid = 'mcp-it-gen-' . bin2hex(random_bytes(8));
        $this->gid = 'mcp-it-life-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach (['mcp_auth_recheck' => 'user_key', 'mcp_auth_users' => 'user_key'] as $table => $column) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter(IdentityKey::user($this->uid))))->executeStatement();
        }
        $qb = $db->getQueryBuilder();
        $qb->delete('mcp_group_life')->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid))))->executeStatement();
        Server::get(OAuthStore::class)->deleteUpToGeneration($this->uid, \OCA\Mcp\Access\Policy\DecimalCounter::MAX);
        parent::tearDown();
    }

    public function testCredentialsWrittenWithoutGenerationColumnsTakeEpochZeroAndGenerationOne(): void {
        $db = $this->official();
        foreach (['mcp_auth_users', 'mcp_auth_recheck', 'mcp_group_life', 'mcp_policy_events'] as $table) {
            $this->assertTrue($db->tableExists($table), $table);
        }
        $now = time();
        $store = Server::get(OAuthStore::class);
        $store->insertCode(['code_hash' => hash('sha256', 'code' . $this->uid), 'user_id' => $this->uid, 'client_id' => 'c', 'redirect_uri' => 'r',
            'code_challenge' => 'x', 'resource' => 'https://r', 'scope' => 'mcp', 'expires_at' => $now + 60], $now);
        $store->insertToken(['user_id' => $this->uid, 'client_id' => 'c', 'resource' => 'https://r', 'scope' => 'mcp',
            'access_hash' => hash('sha256', 'a' . $this->uid), 'access_expires' => $now + 60, 'refresh_hash' => hash('sha256', 'r' . $this->uid),
            'refresh_expires' => $now + 60, 'created_at' => $now], $now);
        $token = $store->findByAccess(hash('sha256', 'a' . $this->uid));
        // Rotation runs in the policy writer's transaction; here a bare one on the official connection.
        $db->beginTransaction();
        $this->assertTrue($store->rotate((int)$token['id'], hash('sha256', 'r' . $this->uid), hash('sha256', 'a2' . $this->uid), $now + 60,
            hash('sha256', 'r2' . $this->uid), $now + 60));
        $db->commit();
        foreach (['mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_spent'] as $table) {
            $qb = $db->getQueryBuilder();
            $result = $qb->select('auth_global_epoch', 'auth_gen')->from($table)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->uid)))->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            $this->assertSame([['auth_global_epoch' => '0', 'auth_gen' => '1']], array_map(static fn ($r) => array_map('strval', $r), $rows), $table);
        }
    }

    public function testGenerationsCausesAndLifecycleCommitOrRollBackWithTheWriter(): void {
        $writer = Server::get(PolicyTransaction::class);
        $users = Server::get(AuthUsersMapper::class);
        $recheck = Server::get(RecheckMapper::class);
        $life = Server::get(GroupLifeMapper::class);
        $second = $this->openIndependentConnection();
        $otherUsers = new AuthUsersMapper($second);
        $otherRecheck = new RecheckMapper($second);
        $otherLife = new GroupLifeMapper($second);
        $gen = static fn (string $c): string => str_repeat($c, 32);

        // Outside any transaction, as a pre-event handler records it; a second session records the same cause anew.
        $account = $recheck->record($this->uid, RecheckMapper::REASON_ACCOUNT, null, $gen('a'), 1);
        $otherRecheck->record($this->uid, RecheckMapper::REASON_ACCOUNT, null, $gen('b'), 2);
        $recheck->record($this->uid, RecheckMapper::REASON_GROUP, $this->gid, $gen('c'), 3);
        $this->assertSame([$gen('b')], array_column(array_filter($recheck->causes($this->uid), fn ($c) => $c['causeKey'] === $account), 'gen'));
        $this->assertCount(2, $otherRecheck->causes($this->uid));

        $command = function (bool $fail) use ($users, $recheck, $life, $account, $gen): \Closure {
            return function () use ($fail, $users, $recheck, $life, $account, $gen): bool {
                $this->assertSame('2', $users->issue($this->uid));
                $this->assertTrue($users->cut($this->uid, '2'));
                $this->assertFalse($recheck->consume($this->uid, $account, $gen('a')), 'stale gen');
                $this->assertTrue($recheck->consume($this->uid, $account, $gen('b')));
                $life->put($this->gid, GroupLifeMapper::ACTIVE, $gen('d'), '1');
                $this->assertTrue($life->transition($this->gid, $gen('d'), GroupLifeMapper::PENDING_DELETE, $gen('e')));
                if ($fail) {
                    throw new RuntimeException('Injected after the B2b writes');
                }
                return true;
            };
        };
        try {
            $writer->runInternal([], fn () => null, $command(true));
            $this->fail('Injected failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected after the B2b writes', $e->getMessage());
        }
        foreach ([$users, $otherUsers] as $mapper) {
            $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $mapper->generations($this->uid));
        }
        $this->assertCount(2, $otherRecheck->causes($this->uid));
        $this->assertNull($otherLife->get($this->gid));
        $this->assertNull($life->get($this->gid));

        $writer->runInternal([], fn () => null, $command(false));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $otherUsers->generations($this->uid));
        $this->assertSame([IdentityKey::group($this->gid)], array_column($otherRecheck->causes($this->uid), 'causeKey'));
        $this->assertSame(['groupId' => $this->gid, 'state' => 'pending_delete', 'gen' => $gen('e'), 'policyRev' => '1'], $otherLife->get($this->gid));
        $this->assertSame(['issue_seq' => '1', 'revoked_below' => '0'], $otherUsers->generations(strtoupper($this->uid)), 'ids are never case-folded');
    }
}
