<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Tests\Unit\FakeDatabase;
use PHPUnit\Framework\TestCase;

/** Runs the production store against the in-memory query builder of {@see FakeDatabase}; no database or server is started. */
final class OAuthStoreTest extends TestCase {
    private FakeDatabase $db;
    private OAuthStore $store;

    protected function setUp(): void {
        $this->db = new FakeDatabase($this, ['mcp_oauth_codes' => [], 'mcp_oauth_tokens' => [], 'mcp_oauth_spent' => []], ['mcp_oauth_codes']);
        $this->store = new OAuthStore($this->db->connection());
    }

    private function grant(int $id = 1, string $uid = 'alice', string $client = 'client'): array {
        return ['id' => $id, 'user_id' => $uid, 'client_id' => $client,
            'refresh_hash' => 'old-' . $id, 'refresh_expires' => 100 + TokenService::REFRESH_TTL,
            'access_hash' => 'access-' . $id, 'access_expires' => 3700];
    }

    public function testProductionRotationRecordsConsumedHashAndReplayDeletesOnlyItsClientAndOwner(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(), $this->grant(2), $this->grant(3, 'bob'), $this->grant(4, 'alice', 'other')];
        $this->assertTrue($this->store->rotate(1, 'old-1', 'new-access', 3701, 'new-refresh', 101 + TokenService::REFRESH_TTL));
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame('old-1', $this->db->tables['mcp_oauth_spent'][0]['refresh_hash']);
        $this->assertFalse($this->store->rotate(1, 'old-1', 'loser-access', 3701, 'loser-refresh', 101 + TokenService::REFRESH_TTL));
        $this->store->revokeReusedRefresh('old-1', 'wrong', 102);
        $this->assertCount(4, $this->db->tables['mcp_oauth_tokens']);
        $this->store->revokeReusedRefresh('old-1', 'client', 102);
        $this->assertSame([3, 4], array_values(array_column($this->db->tables['mcp_oauth_tokens'], 'id')));
        $this->assertSame([], $this->db->tables['mcp_oauth_spent']);
    }

    public function testHistoryWriteFailureRollsBackTheTokenReplacement(): void {
        $original = $this->grant();
        $this->db->tables['mcp_oauth_tokens'] = [$original];
        $this->db->failInsert['mcp_oauth_spent'] = 'history write failed';
        try {
            $this->store->rotate(1, 'old-1', 'new-access', 3701, 'new-refresh', 101 + TokenService::REFRESH_TTL);
            $this->fail('history failure must prevent committing rotation');
        } catch (\RuntimeException $e) {
            $this->assertSame('history write failed', $e->getMessage());
        }
        $this->assertSame([$original], $this->db->tables['mcp_oauth_tokens']);
        $this->assertSame([], $this->db->tables['mcp_oauth_spent']);
    }

    public function testNormalFlowPurgesExpiredHistoryAndDisconnectDeletesTheRemainingHistory(): void {
        $this->db->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 'expired', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 99],
            ['refresh_hash' => 'active', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 101],
        ];
        $this->store->insertToken($this->grant(), 100);
        $this->assertSame(['active'], array_values(array_column($this->db->tables['mcp_oauth_spent'], 'refresh_hash')));
        $this->store->deleteForUser('alice');
        $this->assertSame([], $this->db->tables['mcp_oauth_spent']);
        $this->assertSame([], $this->db->tables['mcp_oauth_tokens']);
    }

    public function testGrantDeletionAndServiceShutdownCleanHistoryAndPendingCodes(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(), $this->grant(2, 'bob')];
        $this->db->tables['mcp_oauth_spent'] = [['grant_id' => 1], ['grant_id' => 2]];
        $this->db->tables['mcp_oauth_codes'] = [['user_id' => 'alice']];
        $this->store->deleteToken(1);
        $this->assertSame([2], array_values(array_column($this->db->tables['mcp_oauth_spent'], 'grant_id')));
        $this->store->deleteAll();
        foreach ($this->db->tables as $rows) { $this->assertSame([], $rows); }
    }

    /** @return array<string, mixed> a stored grant with every column, hashes included */
    private function stored(int $id, string $uid, string $client, int $created, int $refreshExpires = 5000): array {
        return ['id' => $id, 'user_id' => $uid, 'client_id' => $client, 'resource' => 'https://cloud.test/apps/mcp', 'scope' => 'mcp',
            'access_hash' => 'access-' . $id, 'access_expires' => $created + 3600, 'refresh_hash' => 'refresh-' . $id,
            'refresh_expires' => $refreshExpires, 'created_at' => $created];
    }

    /** Listing reads only non-secret columns, skips expired grants, filters, sorts newest first and pages. */
    public function testListGrantsNeverReturnsHashesAndFiltersLiveGrants(): void {
        $this->db->tables['mcp_oauth_tokens'] = [
            $this->stored(1, 'alice', 'https://claude.ai/oauth/mcp-oauth-client-metadata', 10),
            $this->stored(2, 'bob', 'nextcloud-mcp-native', 30),
            $this->stored(3, 'alice', 'https://chatgpt.com/oauth/client.json', 20),
            $this->stored(4, 'carol', 'https://claude.ai/x', 40, 999),
        ];
        $all = $this->store->listGrants(null, '', 50, 0, 1000);
        $this->assertSame([2, 3, 1], array_column($all, 'id'));
        foreach ($all as $row) {
            $this->assertSame(['id', 'user_id', 'client_id', 'scope', 'created_at', 'access_expires', 'refresh_expires'], array_keys($row));
        }
        $this->assertStringNotContainsString('hash', json_encode($all));
        $this->assertSame([3, 1], array_column($this->store->listGrants('alice', '', 50, 0, 1000), 'id'));
        $this->assertSame([3], array_column($this->store->listGrants(null, 'CHATGPT', 50, 0, 1000), 'id'));
        $this->assertSame([2], array_column($this->store->listGrants(null, 'bo', 50, 0, 1000), 'id'));
        $this->assertSame([], $this->store->listGrants(null, '%', 50, 0, 1000), 'a LIKE wildcard in the search is literal');
        $this->assertSame([3], array_column($this->store->listGrants(null, '', 1, 1, 1000), 'id'));
        $this->assertSame(3, $this->store->countGrants(1000));
    }

    /** A user-scoped delete only removes the user's own grant; the admin delete removes any. Spent history goes too. */
    public function testDeleteGrantHonoursTheOwner(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->stored(1, 'alice', 'c', 10), $this->stored(2, 'bob', 'c', 10)];
        $this->db->tables['mcp_oauth_spent'] = [['refresh_hash' => 'x', 'user_id' => 'bob', 'client_id' => 'c', 'grant_id' => 2, 'expires_at' => 5000]];
        $this->assertFalse($this->store->deleteGrant(2, 'alice'));
        $this->assertCount(2, $this->db->tables['mcp_oauth_tokens']);
        $this->assertTrue($this->store->deleteGrant(1, 'alice'));
        $this->assertTrue($this->store->deleteGrant(2, null));
        $this->assertSame([], array_values($this->db->tables['mcp_oauth_tokens']));
        $this->assertSame([], array_values($this->db->tables['mcp_oauth_spent']));
        $this->assertFalse($this->store->deleteGrant(2, null));
    }

    /** @return array<string, mixed> a pending authorization code */
    private function code(string $hash, string $uid = 'alice', string $client = 'client', int $expires = 1000): array {
        return ['code_hash' => $hash, 'user_id' => $uid, 'client_id' => $client, 'redirect_uri' => 'https://claude.ai/cb',
            'code_challenge' => 'ch', 'resource' => 'https://cloud.test/apps/mcp', 'scope' => 'mcp', 'expires_at' => $expires];
    }

    /** A code is worth exactly one exchange: the DELETE that consumes it is what a second request loses. */
    public function testACodeIsConsumedOnceAndAnUnknownOneNever(): void {
        $this->store->insertCode($this->code('h1'), 100);
        $this->store->insertCode($this->code('h2', 'bob'), 100);

        $first = $this->store->consumeCode('h1');
        $this->assertSame('alice', $first['user_id']);
        $this->assertNull($this->store->consumeCode('h1'), 'the second exchange of the same code');
        $this->assertNull($this->store->consumeCode('unknown'));
        $this->assertSame(['h2'], array_column(array_values($this->db->tables['mcp_oauth_codes']), 'code_hash'), 'the other code is untouched');
    }

    /** Two exchanges of one code at the same time: both read it, only the DELETE that removes the row may win. */
    public function testACodeTakenByAConcurrentRequestIsNotIssuedTwice(): void {
        $this->store->insertCode($this->code('h1'), 100);
        $this->db->beforeWrite = function (string $operation, string $table): void {
            if ($operation === 'delete' && $table === 'mcp_oauth_codes') {
                $this->db->tables['mcp_oauth_codes'] = [];
            }
        };

        $this->assertNull($this->store->consumeCode('h1'));
    }

    public function testInsertingACodePurgesTheExpiredOnesAndOnlyThose(): void {
        $this->db->tables['mcp_oauth_codes'] = [
            ['id' => 1] + $this->code('past', expires: 99),
            ['id' => 2] + $this->code('edge', expires: 100),
            ['id' => 3] + $this->code('future', expires: 101),
        ];

        $this->store->insertCode($this->code('new', expires: 700), 100);

        $this->assertSame(['edge', 'future', 'new'], array_column(array_values($this->db->tables['mcp_oauth_codes']), 'code_hash'));
    }

    public function testGrantsAreFoundByTheirOwnHashAndByNoOther(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1), $this->grant(2)];

        $this->assertSame(2, $this->store->findByAccess('access-2')['id']);
        $this->assertSame(1, $this->store->findByRefresh('old-1')['id']);
        $this->assertNull($this->store->findByAccess('old-1'), 'a refresh hash is not an access hash');
        $this->assertNull($this->store->findByRefresh('access-1'), 'an access hash is not a refresh hash');
        $this->assertNull($this->store->findByAccess('nothing'));
    }

    public function testInsertingAGrantPurgesOnlyTheGrantsWhoseRefreshTokenExpired(): void {
        $this->db->tables['mcp_oauth_tokens'] = [
            ['refresh_expires' => 99] + $this->grant(1), ['refresh_expires' => 100] + $this->grant(2), ['refresh_expires' => 101] + $this->grant(3),
        ];

        $this->store->insertToken($this->grant(4), 100);

        $this->assertSame([2, 3, 4], array_values(array_column($this->db->tables['mcp_oauth_tokens'], 'id')));
    }

    /** Disabling a client removes everything it holds, in the three tables, and nothing of anybody else's. */
    public function testDeleteForClientRemovesThatClientsRowsEverywhere(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1, 'alice', 'gone'), $this->grant(2, 'bob', 'gone'), $this->grant(3, 'alice', 'kept')];
        $this->db->tables['mcp_oauth_codes'] = [['id' => 1] + $this->code('c1', client: 'gone'), ['id' => 2] + $this->code('c2', client: 'kept')];
        $this->db->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 's1', 'user_id' => 'alice', 'client_id' => 'gone', 'grant_id' => 1, 'expires_at' => 5000],
            ['refresh_hash' => 's2', 'user_id' => 'alice', 'client_id' => 'kept', 'grant_id' => 3, 'expires_at' => 5000],
        ];

        $this->store->deleteForClient('gone');

        $this->assertSame([3], array_values(array_column($this->db->tables['mcp_oauth_tokens'], 'id')));
        $this->assertSame(['c2'], array_values(array_column($this->db->tables['mcp_oauth_codes'], 'code_hash')));
        $this->assertSame(['s2'], array_values(array_column($this->db->tables['mcp_oauth_spent'], 'refresh_hash')));
    }

    /** A user losing eligibility must not take anybody else's connection with them. */
    public function testDeleteForUserKeepsEveryOtherUsersRows(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1, 'alice'), $this->grant(2, 'bob')];
        $this->db->tables['mcp_oauth_codes'] = [['id' => 1] + $this->code('ca', 'alice'), ['id' => 2] + $this->code('cb', 'bob')];
        $this->db->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 'sa', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 5000],
            ['refresh_hash' => 'sb', 'user_id' => 'bob', 'client_id' => 'client', 'grant_id' => 2, 'expires_at' => 5000],
        ];

        $this->store->deleteForUser('alice');

        $this->assertSame([2], array_values(array_column($this->db->tables['mcp_oauth_tokens'], 'id')));
        $this->assertSame(['cb'], array_values(array_column($this->db->tables['mcp_oauth_codes'], 'code_hash')));
        $this->assertSame(['sb'], array_values(array_column($this->db->tables['mcp_oauth_spent'], 'refresh_hash')));
    }

    /** Two refreshes racing for the same grant: the loser's UPDATE finds the refresh hash already changed. */
    public function testARotationThatLosesTheRaceRollsBackAndRecordsNothing(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1)];
        $this->db->beforeWrite = function (string $operation, string $table): void {
            if ($operation === 'update' && $table === 'mcp_oauth_tokens') {
                $this->db->tables['mcp_oauth_tokens'][0]['refresh_hash'] = 'winner-refresh';
            }
        };

        $this->assertFalse($this->store->rotate(1, 'old-1', 'loser-access', 3701, 'loser-refresh', 5000));

        $this->assertFalse($this->db->inTransaction(), 'the transaction was closed');
        $this->assertSame('access-1', $this->db->tables['mcp_oauth_tokens'][0]['access_hash'], 'the loser installed nothing');
        $this->assertSame([], $this->db->tables['mcp_oauth_spent']);
    }

    public function testARotationOfAnotherGrantsRefreshTokenChangesNothing(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1), $this->grant(2)];

        $this->assertFalse($this->store->rotate(2, 'old-1', 'a', 1, 'r', 2), 'the id and the refresh hash must belong to the same grant');

        $this->assertSame(['access-1', 'access-2'], array_column($this->db->tables['mcp_oauth_tokens'], 'access_hash'));
        $this->assertSame([], $this->db->tables['mcp_oauth_spent']);
    }

    /** A replay of a spent token only counts while its history is alive. */
    public function testAReplayAfterTheHistoryExpiredRevokesNothing(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1)];
        $this->db->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 'spent', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 99],
        ];

        $this->store->revokeReusedRefresh('spent', 'client', 100);

        $this->assertCount(1, $this->db->tables['mcp_oauth_tokens']);
    }

    public function testAReplayOfALiveSpentTokenRevokesOnlyThatUsersGrantsOfThatClient(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->grant(1, 'alice'), $this->grant(2, 'bob'), $this->grant(3, 'alice', 'other')];
        $this->db->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 'spent', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 100],
        ];

        $this->store->revokeReusedRefresh('spent', 'client', 100);

        $this->assertSame([2, 3], array_values(array_column($this->db->tables['mcp_oauth_tokens'], 'id')));
    }

    public function testAGrantExpiringExactlyNowIsStillListedAndCounted(): void {
        $this->db->tables['mcp_oauth_tokens'] = [$this->stored(1, 'alice', 'c', 10, 1000), $this->stored(2, 'alice', 'c', 11, 999)];

        $this->assertSame([1], array_column($this->store->listGrants(null, '', 50, 0, 1000), 'id'));
        $this->assertSame(1, $this->store->countGrants(1000));
    }
}
