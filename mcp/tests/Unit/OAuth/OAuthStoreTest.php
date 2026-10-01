<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Runs the production store against OCP query-builder doubles; no database or server is started. */
final class OAuthStoreTest extends TestCase {
    private array $tables = [];
    private ?array $snapshot = null;
    private bool $failHistoryWrite = false;
    private OAuthStore $store;

    protected function setUp(): void {
        $this->tables = ['mcp_oauth_codes' => [], 'mcp_oauth_tokens' => [], 'mcp_oauth_spent' => []];
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
        $db->method('beginTransaction')->willReturnCallback(function (): void { $this->snapshot = $this->tables; });
        $db->method('commit')->willReturnCallback(function (): void { $this->snapshot = null; });
        $db->method('rollBack')->willReturnCallback(function (): void {
            $this->tables = $this->snapshot;
            $this->snapshot = null;
        });
        $this->store = new OAuthStore($db);
    }

    private function builder(): IQueryBuilder {
        $qb = $this->createMock(IQueryBuilder::class);
        $state = (object)['table' => '', 'operation' => '', 'values' => [], 'conditions' => [], 'params' => []];
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use ($state): string {
            $key = ':p' . count($state->params);
            $state->params[$key] = $value;
            return $key;
        });
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'lt'] as $op) {
            $expr->method($op)->willReturnCallback(fn ($column, $param) => json_encode([$op, $column, $state->params[$param]]));
        }
        $qb->method('expr')->willReturn($expr);
        foreach (['delete', 'update', 'insert', 'from'] as $op) {
            $qb->method($op)->willReturnCallback(function ($table) use ($state, $op, $qb) {
                $state->table = $table;
                $state->operation = $op;
                return $qb;
            });
        }
        foreach (['set', 'setValue'] as $method) {
            $qb->method($method)->willReturnCallback(function ($column, $param) use ($state, $qb) {
                $state->values[$column] = $state->params[$param];
                return $qb;
            });
        }
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(function ($condition) use ($state, $qb) {
                $state->conditions[] = json_decode($condition, true);
                return $qb;
            });
        }
        $matches = static function (array $row) use ($state): bool {
            foreach ($state->conditions as [$op, $column, $value]) {
                if ($op === 'eq' ? ($row[$column] ?? null) !== $value : ($row[$column] ?? null) >= $value) {
                    return false;
                }
            }
            return true;
        };
        $qb->method('select')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('executeQuery')->willReturnCallback(function () use ($state, $matches): IResult {
            $rows = array_values(array_filter($this->tables[$state->table], $matches));
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturn($rows[0] ?? false);
            return $result;
        });
        $qb->method('executeStatement')->willReturnCallback(function () use ($state, $matches): int {
            if ($state->operation === 'insert') {
                if ($this->failHistoryWrite && $state->table === 'mcp_oauth_spent') {
                    throw new \RuntimeException('history write failed');
                }
                $this->tables[$state->table][] = $state->values;
                return 1;
            }
            $count = 0;
            foreach ($this->tables[$state->table] as $key => $row) {
                if (!$matches($row)) { continue; }
                $count++;
                if ($state->operation === 'delete') {
                    unset($this->tables[$state->table][$key]);
                } else {
                    $this->tables[$state->table][$key] = array_replace($row, $state->values);
                }
            }
            return $count;
        });
        return $qb;
    }

    private function grant(int $id = 1, string $uid = 'alice', string $client = 'client'): array {
        return ['id' => $id, 'user_id' => $uid, 'client_id' => $client,
            'refresh_hash' => 'old-' . $id, 'refresh_expires' => 100 + TokenService::REFRESH_TTL,
            'access_hash' => 'access-' . $id, 'access_expires' => 3700];
    }

    public function testProductionRotationRecordsConsumedHashAndReplayDeletesOnlyItsClientAndOwner(): void {
        $this->tables['mcp_oauth_tokens'] = [$this->grant(), $this->grant(2), $this->grant(3, 'bob'), $this->grant(4, 'alice', 'other')];
        $this->assertTrue($this->store->rotate(1, 'old-1', 'new-access', 3701, 'new-refresh', 101 + TokenService::REFRESH_TTL));
        $this->assertNull($this->snapshot);
        $this->assertSame('old-1', $this->tables['mcp_oauth_spent'][0]['refresh_hash']);
        $this->assertFalse($this->store->rotate(1, 'old-1', 'loser-access', 3701, 'loser-refresh', 101 + TokenService::REFRESH_TTL));
        $this->store->revokeReusedRefresh('old-1', 'wrong', 102);
        $this->assertCount(4, $this->tables['mcp_oauth_tokens']);
        $this->store->revokeReusedRefresh('old-1', 'client', 102);
        $this->assertSame([3, 4], array_values(array_column($this->tables['mcp_oauth_tokens'], 'id')));
        $this->assertSame([], $this->tables['mcp_oauth_spent']);
    }

    public function testHistoryWriteFailureRollsBackTheTokenReplacement(): void {
        $original = $this->grant();
        $this->tables['mcp_oauth_tokens'] = [$original];
        $this->failHistoryWrite = true;
        try {
            $this->store->rotate(1, 'old-1', 'new-access', 3701, 'new-refresh', 101 + TokenService::REFRESH_TTL);
            $this->fail('history failure must prevent committing rotation');
        } catch (\RuntimeException $e) {
            $this->assertSame('history write failed', $e->getMessage());
        }
        $this->assertSame([$original], $this->tables['mcp_oauth_tokens']);
        $this->assertSame([], $this->tables['mcp_oauth_spent']);
    }

    public function testNormalFlowPurgesExpiredHistoryAndDisconnectDeletesTheRemainingHistory(): void {
        $this->tables['mcp_oauth_spent'] = [
            ['refresh_hash' => 'expired', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 99],
            ['refresh_hash' => 'active', 'user_id' => 'alice', 'client_id' => 'client', 'grant_id' => 1, 'expires_at' => 101],
        ];
        $this->store->insertToken($this->grant(), 100);
        $this->assertSame(['active'], array_values(array_column($this->tables['mcp_oauth_spent'], 'refresh_hash')));
        $this->store->deleteForUser('alice');
        $this->assertSame([], $this->tables['mcp_oauth_spent']);
        $this->assertSame([], $this->tables['mcp_oauth_tokens']);
    }

    public function testGrantDeletionAndServiceShutdownCleanHistoryAndPendingCodes(): void {
        $this->tables['mcp_oauth_tokens'] = [$this->grant(), $this->grant(2, 'bob')];
        $this->tables['mcp_oauth_spent'] = [['grant_id' => 1], ['grant_id' => 2]];
        $this->tables['mcp_oauth_codes'] = [['user_id' => 'alice']];
        $this->store->deleteToken(1);
        $this->assertSame([2], array_values(array_column($this->tables['mcp_oauth_spent'], 'grant_id')));
        $this->store->deleteAll();
        foreach ($this->tables as $rows) { $this->assertSame([], $rows); }
    }
}
