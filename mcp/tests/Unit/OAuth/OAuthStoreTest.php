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
        $db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
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
        $state = (object)['table' => '', 'operation' => '', 'values' => [], 'conditions' => [], 'params' => [], 'columns' => ['*'], 'order' => [], 'limit' => null, 'offset' => 0, 'count' => false];
        $qb->method('createNamedParameter')->willReturnCallback(function ($value) use ($state): string {
            $key = ':p' . count($state->params);
            $state->params[$key] = $value;
            return $key;
        });
        $expr = $this->createMock(IExpressionBuilder::class);
        foreach (['eq', 'lt', 'gte', 'iLike'] as $op) {
            $expr->method($op)->willReturnCallback(fn ($column, $param) => json_encode([$op, $column, $state->params[$param]]));
        }
        $composites = new \SplObjectStorage();
        $expr->method('orX')->willReturnCallback(function (...$parts) use ($composites) {
            $composite = $this->createMock(\OCP\DB\QueryBuilder\ICompositeExpression::class);
            $composites[$composite] = ['or', array_map(fn ($p) => json_decode($p, true), $parts)];
            return $composite;
        });
        $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $func->method('count')->willReturnCallback(function () use ($state) {
            $state->count = true;
            return $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
        });
        $qb->method('func')->willReturn($func);
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
            $qb->method($method)->willReturnCallback(function ($condition) use ($state, $qb, $composites) {
                $state->conditions[] = is_object($condition) ? $composites[$condition] : json_decode($condition, true);
                return $qb;
            });
        }
        $holds = static function (array $condition, array $row) use (&$holds): bool {
            if ($condition[0] === 'or') {
                return array_filter($condition[1], fn (array $part) => $holds($part, $row)) !== [];
            }
            [$op, $column, $value] = $condition;
            $actual = $row[$column] ?? null;
            return match ($op) {
                'eq' => $actual === $value,
                'lt' => $actual < $value,
                'gte' => $actual >= $value,
                'iLike' => preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote(stripcslashes(str_replace(['\\%', '\\_'], ["\x01", "\x02"], $value)), '/')) . '$/i',
                    str_replace(["\x01", "\x02"], ['%', '_'], (string)$actual)) === 1,
            };
        };
        $matches = static function (array $row) use ($state, $holds): bool {
            foreach ($state->conditions as $condition) {
                if (!$holds($condition, $row)) {
                    return false;
                }
            }
            return true;
        };
        $qb->method('select')->willReturnCallback(function (...$columns) use ($state, $qb) {
            $state->columns = $columns;
            return $qb;
        });
        $qb->method('setMaxResults')->willReturnCallback(function ($limit) use ($state, $qb) {
            $state->limit = $limit;
            return $qb;
        });
        $qb->method('setFirstResult')->willReturnCallback(function ($offset) use ($state, $qb) {
            $state->offset = $offset;
            return $qb;
        });
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(function ($column, $direction = 'ASC') use ($state, $qb) {
                $state->order[] = [$column, strtoupper((string)$direction)];
                return $qb;
            });
        }
        $qb->method('executeQuery')->willReturnCallback(function () use ($state, $matches): IResult {
            $rows = array_values(array_filter($this->tables[$state->table], $matches));
            usort($rows, static function (array $a, array $b) use ($state): int {
                foreach ($state->order as [$column, $direction]) {
                    $cmp = ($a[$column] ?? null) <=> ($b[$column] ?? null);
                    if ($cmp !== 0) {
                        return $direction === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
            $count = count($rows);
            $rows = array_slice($rows, $state->offset, $state->limit);
            if ($state->columns !== ['*'] && !$state->count) {
                $rows = array_map(static fn (array $row) => array_intersect_key($row, array_flip($state->columns)), $rows);
            }
            $result = $this->createMock(IResult::class);
            $result->method('fetch')->willReturn($rows[0] ?? false);
            $result->method('fetchAll')->willReturn($rows);
            $result->method('fetchOne')->willReturn($count);
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

    /** @return array<string, mixed> a stored grant with every column, hashes included */
    private function stored(int $id, string $uid, string $client, int $created, int $refreshExpires = 5000): array {
        return ['id' => $id, 'user_id' => $uid, 'client_id' => $client, 'resource' => 'https://cloud.test/apps/mcp', 'scope' => 'mcp',
            'access_hash' => 'access-' . $id, 'access_expires' => $created + 3600, 'refresh_hash' => 'refresh-' . $id,
            'refresh_expires' => $refreshExpires, 'created_at' => $created];
    }

    /** Listing reads only non-secret columns, skips expired grants, filters, sorts newest first and pages. */
    public function testListGrantsNeverReturnsHashesAndFiltersLiveGrants(): void {
        $this->tables['mcp_oauth_tokens'] = [
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
        $this->tables['mcp_oauth_tokens'] = [$this->stored(1, 'alice', 'c', 10), $this->stored(2, 'bob', 'c', 10)];
        $this->tables['mcp_oauth_spent'] = [['refresh_hash' => 'x', 'user_id' => 'bob', 'client_id' => 'c', 'grant_id' => 2, 'expires_at' => 5000]];
        $this->assertFalse($this->store->deleteGrant(2, 'alice'));
        $this->assertCount(2, $this->tables['mcp_oauth_tokens']);
        $this->assertTrue($this->store->deleteGrant(1, 'alice'));
        $this->assertTrue($this->store->deleteGrant(2, null));
        $this->assertSame([], array_values($this->tables['mcp_oauth_tokens']));
        $this->assertSame([], array_values($this->tables['mcp_oauth_spent']));
        $this->assertFalse($this->store->deleteGrant(2, null));
    }
}
