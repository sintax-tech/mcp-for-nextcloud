<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class GroupGrantsMapperTest extends TestCase {
    private SqliteDatabase $db;

    protected function setUp(): void {
        $this->db = new SqliteDatabase($this, [new Version001100Date20261014000000()]);
    }

    /** Fixture writes bypass the read-only mapper; there is no production collision switch or writer. */
    private function seed(string $gid, string $module = 'files', string $operation = 'read', ?string $key = null): void {
        $this->db->dbal->insert('mcp_group_grants', [
            'group_key' => $key ?? hash('sha256', "group\0" . $gid),
            'group_id' => $gid, 'module' => $module, 'operation' => $operation,
        ]);
    }

    public function testCoreParameterObjectsReachTheInExpressionWithoutStringReturnCoercion(): void {
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls([
            'group_key' => hash('sha256', "group\0team"), 'group_id' => 'team', 'module' => 'files', 'operation' => 'read',
        ], false);
        $this->assertSame([['groupId' => 'team', 'grants' => ['files' => ['read']]]],
            (new GroupGrantsMapper($this->parameterConnection($result)))->forGroups(['team']));
    }

    public function testInvalidRowClosesTheCursorExactlyOnceBeforePropagatingTheError(): void {
        $result = $this->createMock(IResult::class);
        $result->expects($this->once())->method('fetch')->willReturn([
            'group_key' => hash('sha256', "group\0team"), 'group_id' => 'another group', 'module' => 'files', 'operation' => 'read',
        ]);
        $result->expects($this->once())->method('closeCursor')->willReturn(true);
        $this->expectException(UnexpectedValueException::class);
        (new GroupGrantsMapper($this->parameterConnection($result)))->forGroups(['team']);
    }

    public function testSqliteHarnessBindsParameterObjectsForWritesComparisonsAndInLists(): void {
        $connection = $this->db->connection();
        $gid = "team's opaque id";
        $key = hash('sha256', "group\0" . $gid);
        $insert = $connection->getQueryBuilder();
        $gidParameter = $insert->createNamedParameter($gid);
        $this->assertInstanceOf(IParameter::class, $gidParameter);
        $this->assertInstanceOf(\Stringable::class, $gidParameter);
        $insert->insert('mcp_group_grants')
            ->setValue('group_key', $insert->createNamedParameter($key))
            ->setValue('group_id', $gidParameter)
            ->setValue('module', $insert->createNamedParameter('files'))
            ->setValue('operation', $insert->createNamedParameter('read'))->executeStatement();
        $select = $connection->getQueryBuilder();
        $result = $select->select('group_id')->from('mcp_group_grants')
            ->where($select->expr()->eq('group_key', $select->createNamedParameter($key)))->executeQuery();
        try {
            $this->assertSame(['group_id' => $gid], $result->fetch());
        } finally {
            $result->closeCursor();
        }
        $this->assertSame([['groupId' => $gid, 'grants' => ['files' => ['read']]]],
            (new GroupGrantsMapper($connection))->forGroups([$gid]));
    }

    /** The core returns a concrete Stringable IParameter, not a Doctrine placeholder string. */
    private function parameterConnection(IResult $result): IDBConnection {
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('createNamedParameter')->willReturnCallback(static fn () => new class implements IParameter {
            public function __toString(): string {
                return ':dcValue1';
            }
        });
        foreach (['select', 'from', 'where'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('in')->willReturnCallback(function ($column, array $parameters): string {
            $this->assertSame('group_key', $column);
            $this->assertCount(1, $parameters);
            $this->assertInstanceOf(IParameter::class, $parameters[0]);
            return $column . ' IN (' . (string)$parameters[0] . ')';
        });
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);
        $connection = $this->createMock(IDBConnection::class);
        $connection->method('getQueryBuilder')->willReturn($qb);
        return $connection;
    }

    public function testEmptyInputDoesNotTouchTheDatabaseAndMissingGroupsHaveNoGrants(): void {
        $connection = $this->createMock(IDBConnection::class);
        $connection->expects($this->never())->method('getQueryBuilder');
        $this->assertSame([], (new GroupGrantsMapper($connection))->forGroups([]));
        $this->assertSame([
            ['groupId' => '1', 'grants' => []], ['groupId' => '01', 'grants' => []],
        ], (new GroupGrantsMapper($this->db->connection()))->forGroups(['1', '01']));
        $this->assertSame(0, (int)$this->db->dbal->fetchOne('SELECT COUNT(*) FROM mcp_group_grants'));
    }

    public function testGroupedReadsPreserveOpaqueIdsAndDoNotReadUnrequestedGroups(): void {
        $ids = ['1', '01', 'Team', 'team', "\u{00e9}", "e\u{0301}", str_repeat('💡', 300), "x' OR 1=1 --"];
        foreach ($ids as $gid) {
            $this->seed($gid);
        }
        $this->seed('Team', 'notes', 'edit');
        $this->seed('Team', '_system', 'eligible');
        $this->seed('team', 'files', 'edit');
        $this->seed('not requested', 'notes', 'delete');
        $requested = [...array_reverse($ids), 'Team', 'missing'];
        $expected = array_map(static fn (string $gid): array => ['groupId' => $gid, 'grants' => match ($gid) {
            'Team' => ['_system' => ['eligible'], 'files' => ['read'], 'notes' => ['edit']],
            'team' => ['files' => ['edit', 'read']],
            default => ['files' => ['read']],
        }], array_reverse($ids));
        $expected[] = ['groupId' => 'missing', 'grants' => []];
        $this->assertSame($expected, (new GroupGrantsMapper($this->db->connection()))->forGroups($requested));
    }

    public function testLargeRequestsAreChunkedWithoutLosingGroupsOrQueryingOneGroupAtATime(): void {
        $ids = [];
        for ($i = 0; $i < 1001; $i++) {
            $ids[] = 'group-' . $i;
            $this->seed('group-' . $i, 'notes', 'edit');
        }
        $real = $this->db->connection();
        $queries = 0;
        $connection = $this->createMock(IDBConnection::class);
        $connection->method('getQueryBuilder')->willReturnCallback(function () use ($real, &$queries): IQueryBuilder {
            $queries++;
            return $real->getQueryBuilder();
        });
        $expected = array_map(static fn (string $gid): array => ['groupId' => $gid, 'grants' => ['notes' => ['edit']]], $ids);
        $this->assertSame($expected, (new GroupGrantsMapper($connection))->forGroups([...$ids, ...$ids]));
        $this->assertGreaterThan(1, $queries, 'An unbounded IN list must not reach the database');
        $this->assertLessThanOrEqual(10, $queries, 'Grouping must avoid a query per id');
    }

    public function testPrimaryKeyRejectsDuplicatePermissionButAllowsOtherOperationsAndGroups(): void {
        $this->seed('one');
        $this->seed('one', 'files', 'edit');
        $this->seed('two');
        try {
            $this->seed('one');
            $this->fail('Duplicate group/module/operation must be rejected by the database');
        } catch (UniqueConstraintViolationException) {
        }
        $this->assertSame(3, (int)$this->db->dbal->fetchOne('SELECT COUNT(*) FROM mcp_group_grants'));
    }

    public function testMismatchedOriginalIdFailsTheWholeReadInsteadOfGrantingTheDigestOwner(): void {
        $this->seed('valid');
        // Represents a colliding or corrupted row under the requested group's digest, without a test hash in lib/.
        $this->seed('different identity', 'notes', 'edit', hash('sha256', "group\0requested"));
        $this->expectException(UnexpectedValueException::class);
        (new GroupGrantsMapper($this->db->connection()))->forGroups(['valid', 'requested']);
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidInputs(): array {
        return [
            'not a list' => [['gid' => 'team']], 'integer' => [[1]], 'null' => [[null]], 'boolean' => [[false]],
            'empty' => [['']], 'nul' => [["bad\0id"]], 'invalid utf8' => [["bad\xff"]],
            'late invalid id' => [['team', '']],
        ];
    }

    /** @param array<mixed> $ids */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejectedBeforeAnyQuery(array $ids): void {
        $connection = $this->createMock(IDBConnection::class);
        $connection->expects($this->never())->method('getQueryBuilder');
        $this->expectException(InvalidArgumentException::class);
        (new GroupGrantsMapper($connection))->forGroups($ids);
    }

    /** @return array<string, array{string,string}> */
    public static function invalidStoredPermissions(): array {
        return [
            'unknown module' => ['unknown', 'read'], 'unknown operation' => ['files', 'delete'],
            'group deny' => ['_system', 'deny'], 'wrong system operation' => ['_system', 'read'],
            'eligibility on ordinary module' => ['files', 'eligible'], 'empty operation' => ['files', ''],
            'case changed' => ['Files', 'read'],
        ];
    }

    #[DataProvider('invalidStoredPermissions')]
    public function testInvalidStoredPermissionFailsTheWholeRead(string $module, string $operation): void {
        $this->seed('team');
        $this->seed('team', $module, $operation);
        $this->expectException(UnexpectedValueException::class);
        (new GroupGrantsMapper($this->db->connection()))->forGroups(['team']);
    }

    public function testEveryCurrentCatalogPairAndSystemEligibilityCanBeRead(): void {
        $expected = GrantPolicy::CATALOG;
        $expected['_system'] = ['eligible'];
        foreach ($expected as $module => &$operations) {
            foreach ($operations as $operation) {
                $this->seed('team', $module, $operation);
            }
            sort($operations, SORT_STRING);
        }
        unset($operations);
        ksort($expected, SORT_STRING);
        $this->assertSame([['groupId' => 'team', 'grants' => $expected]],
            (new GroupGrantsMapper($this->db->connection()))->forGroups(['team']));
    }

    public function testNoInheritedMutationApiAndReadsDoNotChangeExistingPolicyOrCredentials(): void {
        $this->assertFalse(is_subclass_of(GroupGrantsMapper::class, QBMapper::class));
        foreach (['insert', 'update', 'delete', 'deleteById', '__call'] as $method) {
            $this->assertFalse(method_exists(GroupGrantsMapper::class, $method));
        }
        $this->db = new SqliteDatabase($this, [new Version000500Date20260930000000(),
            new Version000700Date20261001000000(), new Version000800Date20261002000000(),
            new Version000800Date20261002010000(), new Version001002Date20261008000000(),
            new Version001100Date20261014000000()]);
        // Existing credential bytes must survive reads of both empty and populated group storage.
        $this->db->dbal->insert('mcp_oauth_tokens', ['id' => 1, 'user_id' => 'alice', 'client_id' => 'test-client',
            'resource' => 'https://example.invalid/apps/mcp/', 'scope' => 'mcp', 'access_hash' => str_repeat('a', 64),
            'access_expires' => 2000000000, 'refresh_hash' => str_repeat('b', 64), 'refresh_expires' => 2000000001,
            'created_at' => 1]);
        $config = new InMemoryConfig();
        $policy = InMemoryConfig::policy($config->mock($this), new InMemoryOAuthStore());
        $policy->setGlobalEnabled(true);
        $policy->setEligible('alice', true);
        $policy->setConnected('alice', true);
        $policy->setGrant('alice', 'files', 'read', false);
        $policy->setGrant('alice', 'notes', 'edit', true);
        $before = [$config->app, $config->user, $policy->forUsers(['alice', 'bob']), $policy->canConnect('alice')];
        $tokens = $this->db->dbal->fetchAllAssociative('SELECT * FROM mcp_oauth_tokens');
        $changes = (int)$this->db->dbal->fetchOne('SELECT total_changes()');
        $mapper = new GroupGrantsMapper($this->db->connection());
        $this->assertSame([['groupId' => 'team', 'grants' => []]], $mapper->forGroups(['team']));
        $this->assertSame($changes, (int)$this->db->dbal->fetchOne('SELECT total_changes()'));
        $this->seed('team', 'notes', 'delete');
        $this->seed('team', '_system', 'eligible');
        $changes = (int)$this->db->dbal->fetchOne('SELECT total_changes()');
        $this->assertSame([['groupId' => 'team', 'grants' => ['_system' => ['eligible'], 'notes' => ['delete']]]], $mapper->forGroups(['team']));
        $this->assertSame($changes, (int)$this->db->dbal->fetchOne('SELECT total_changes()'));
        $this->assertSame($tokens, $this->db->dbal->fetchAllAssociative('SELECT * FROM mcp_oauth_tokens'));
        $this->assertSame($before, [$config->app, $config->user, $policy->forUsers(['alice', 'bob']), $policy->canConnect('alice')]);
        $this->assertFalse($policy->granted('alice', 'notes', 'delete'), 'Stored group rows have no runtime effect in B1');
        $this->assertFalse($policy->eligible('bob'), 'B1 does not activate group eligibility');
    }
}
