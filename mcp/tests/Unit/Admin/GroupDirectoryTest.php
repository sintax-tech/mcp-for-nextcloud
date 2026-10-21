<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use OCA\Mcp\Controller\GroupDirectoryController;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Access\Grants\Groups\GroupDirectory;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Unit\ArrayGroupBackend;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** T16/T17: group paging, source diagnosis, complete counters and unknown distinct from zero. */
final class GroupDirectoryTest extends TestCase {
    private InMemoryConfig $data;
    private \OCP\IConfig $config;
    private array $native = [];
    private array $ldap = [];
    private array $names = [];
    private bool $knownCount = true;
    private bool $brokenCount = false;
    private int $sourceReads = 0;
    /** A group whose source cannot be read, to prove an unknown source is never shown as blocked. */
    private ?string $failingGid = null;
    private IGroupManager $manager;
    private GroupDirectory $directory;

    protected function setUp(): void {
        $this->data = new InMemoryConfig();
        $this->data->groupBackends = [new ArrayGroupBackend(fn () => $this->native, 'Database', function (string $gid) { ++$this->sourceReads; if ($gid === $this->failingGid) throw new \RuntimeException('Backend down'); }),
            new ArrayGroupBackend(fn () => $this->ldap, 'LDAP', function (string $gid) { ++$this->sourceReads; if ($gid === $this->failingGid) throw new \RuntimeException('Backend down'); })];
        $this->config = $this->data->mock($this);
        $writer = InMemoryConfig::services($this->config, new InMemoryOAuthStore());
        $resolver = InMemoryConfig::resolver($this->config);
        $db = InMemoryConfig::groupDatabase($this->config)->connection();
        $grants = new GroupGrantsMapper($db);
        $life = new GroupLifeMapper($db);
        $this->manager = $this->createMock(IGroupManager::class);
        $this->manager->method('getBackends')->willReturn($this->data->groupBackends);
        $group = function (string $gid): IGroup {
            $group = $this->createMock(IGroup::class);
            $group->method('getGID')->willReturn($gid);
            $group->method('getDisplayName')->willReturn($this->names[$gid] ?? $gid);
            $group->method('count')->willReturnCallback(function () use ($gid) {
                if ($this->brokenCount) throw new \RuntimeException('Directory unavailable');
                return $this->knownCount ? count(array_unique($this->native[$gid] ?? $this->ldap[$gid] ?? [])) : false;
            });
            return $group;
        };
        $this->manager->method('search')->with('')->willReturnCallback(fn () => array_map($group, array_map('strval', array_keys($this->native + $this->ldap))));
        $this->manager->method('get')->willReturnCallback(fn (string $gid) => isset($this->native[$gid]) || isset($this->ldap[$gid]) ? $group($gid) : null);
        $classifier = new GroupSourceClassifier($this->manager);
        $this->directory = new GroupDirectory($this->manager, $db, $grants, $life, $classifier, $resolver, InMemoryConfig::state($this->config));
    }

    public function testFiftyGroupPageUsesBatchSqlAndOneClassificationPerGroup(): void {
        for ($i = 0; $i < 50; ++$i) $this->native['group-' . $i] = [];
        $database = InMemoryConfig::groupDatabase($this->config);
        $database->queryCount = $this->sourceReads = 0;
        $start = hrtime(true);
        $rows = $this->directory->page()['groups'];
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertCount(50, $rows);
        $this->assertSame(4, $database->queryCount, 'two app scans plus two batched policy reads');
        $this->assertSame(100, $this->sourceReads, 'one existence read per backend per displayed group');
        fwrite(STDERR, sprintf("T17 page 50: %d app SELECTs, %d backend existence calls, %.3f ms (SQLite memory + directory doubles; state reads mocked)\n",
            $database->queryCount, $this->sourceReads, $ms));
    }

    public function testOrdinaryContextCannotBypassIndividualDenyThroughInheritance(): void {
        $this->data->user['ana']['mcp']['grant_notes_read'] = '0';
        $context = InMemoryConfig::resolver($this->config)->begin('ana');
        try {
            $this->assertFalse($context->granted('notes', 'read')->allowed);
            $this->expectException(\LogicException::class);
            $context->inherited('notes', 'read');
        } finally {
            $context->close();
        }
    }

    public function testInvalidDirectoryIdIsDiagnosedWithoutBlockingValidRowsOrInventingAnIdentity(): void {
        $this->native["bad\xff"] = [];
        $this->native['valid'] = [];
        $rows = $this->directory->page()['groups'];
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['groupId']);
        $this->assertTrue($rows[0]['invalidId']);
        $this->assertNull($rows[0]['trusted']);
        $this->assertSame('valid', $rows[1]['groupId']);
        $this->assertIsString(json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function testPagingHasNoTwoHundredGroupCutoffAndSearchesNameOrId(): void {
        for ($i = 224; $i >= 0; --$i) $this->native[sprintf('g%03d', $i)] = [];
        $this->names['g224'] = 'A special group';
        $first = $this->directory->page();
        $this->assertCount(50, $first['groups']);
        $this->assertTrue($first['hasMore']);
        $this->assertSame('g224', $first['groups'][0]['groupId']);
        $last = $this->directory->page('', 5);
        $this->assertCount(25, $last['groups']);
        $this->assertFalse($last['hasMore']);
        $this->assertSame('g224', $this->directory->page('special')['groups'][0]['groupId']);
        $this->assertSame('g223', $this->directory->page('g223')['groups'][0]['groupId']);
        $this->names['g000'] = $this->names['g001'] = 'Same';
        $this->assertSame(['g000', 'g001'], array_column($this->directory->page('Same')['groups'], 'groupId'));
    }

    public function testLdapIsDisabledAndOrphanedPoliciesRemainDiagnosable(): void {
        $this->ldap['external'] = ['ana'];
        InMemoryConfig::groupPolicy($this->config, 'gone', ['notes' => ['edit']], 'needs_confirm');
        $rows = $this->directory->page()['groups'];
        $this->assertSame(['external', 'gone'], array_column($rows, 'groupId'));
        $this->assertFalse($rows[0]['trusted']);
        $this->assertFalse($rows[1]['exists']);
        $this->assertSame('needs_confirm', $rows[1]['state']);
        $this->assertSame(['members' => 1, 'overrides' => null, 'permissions' => null], $this->directory->detail('external')['counts']);
        $this->assertSame(0, $this->directory->detail('gone')['counts']['members']);
    }

    public function testOverridesCountPresenceEvenWhenEqualToInheritanceAndMembersAreUnique(): void {
        $this->native['team'] = ['ana', 'ben', 'cam', 'ana'];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->data->user = [
            'ana' => ['mcp' => ['eligible' => '1', 'grant_notes_edit' => '1']],
            'ben' => ['mcp' => ['eligible' => '0', 'grant_notes_edit' => '0']],
            'cam' => ['mcp' => ['grant_notes_read' => '1']],
        ];
        $counts = $this->directory->detail('team')['counts'];
        $this->assertSame(3, $counts['members']);
        $this->assertSame(3, $counts['overrides']);
        $this->assertSame(['overrides' => 2, 'different' => 1], $counts['permissions']['_system']['eligible']);
        $this->assertSame(['overrides' => 2, 'different' => 1], $counts['permissions']['notes']['edit']);
        $this->assertSame(['overrides' => 1, 'different' => 0], $counts['permissions']['notes']['read']);
        $this->assertSame(['overrides' => 0, 'different' => 0], $counts['permissions']['files']['edit']);
        $this->assertSame('active', $this->directory->detail('team')['state']);
    }

    public function testUnknownCountAndReadFailureNeverBecomeZeroButEmptyGroupIsZero(): void {
        $this->native['empty'] = [];
        $this->assertSame(0, $this->directory->detail('empty')['counts']['overrides']);
        $this->knownCount = false;
        $this->assertSame(['members' => null, 'overrides' => null, 'permissions' => null], $this->directory->detail('empty')['counts']);
        $this->knownCount = true;
        $this->brokenCount = true;
        $this->assertNull($this->directory->detail('empty')['counts']['members']);
    }

    public function testIndeterminateInheritanceHasUnknownDifferenceCountAndDoesNotWriteLifecycle(): void {
        $this->native['team'] = ['ana'];
        InMemoryConfig::groupPolicy($this->config, 'team', ['_system' => ['eligible'], 'notes' => ['edit']], 'needs_confirm');
        $this->data->user = ['ana' => ['mcp' => ['grant_notes_edit' => '1']]];
        $detail = $this->directory->detail('team');
        $this->assertSame(['overrides' => 1, 'different' => null], $detail['counts']['permissions']['notes']['edit']);
        $this->assertSame('needs_confirm', $detail['state']);
    }

    /** T17 cost guard: batch SQL/config reads do not grow once per member. No real directory latency is simulated. */
    public function testThousandMemberStatisticsUseBatchedGrantReads(): void {
        $this->native['large'] = array_map(static fn (int $n): string => 'user-' . $n, range(1, 1000));
        InMemoryConfig::groupPolicy($this->config, 'large', ['_system' => ['eligible']]);
        foreach ($this->native['large'] as $uid) $this->data->user[$uid]['mcp']['eligible'] = '1';
        $database = InMemoryConfig::groupDatabase($this->config);
        $database->queryCount = $this->data->batchCalls = 0;
        $start = hrtime(true);
        $counts = $this->directory->detail('large')['counts'];
        $ms = (hrtime(true) - $start) / 1e6;
        $this->assertSame(1000, $counts['members']);
        $this->assertSame(1000, $counts['overrides']);
        $this->assertSame(['overrides' => 1000, 'different' => 0], $counts['permissions']['_system']['eligible']);
        // Two reads for the group header, then two group/lifecycle SELECTs per block of 200. No scope SQL/member.
        $this->assertSame(12, $database->queryCount);
        $this->assertSame(5 * (array_sum(array_map('count', \OCA\Mcp\Access\Grants\GrantPolicy::CATALOG)) + 2), $this->data->batchCalls);
        fwrite(STDERR, sprintf("T17 group 1000: %d app SELECTs, %d official config batch calls, %.3f ms (SQLite memory + directory/config doubles; state reads mocked)\n",
            $database->queryCount, $this->data->batchCalls, $ms));
    }

    public function testGrantedFilterPagesAndSearchesOnTheServerAndCountsEachFilter(): void {
        for ($i = 0; $i < 120; ++$i) $this->native[sprintf('g%03d', $i)] = [];
        // Every other group grants something; one policy-only group grants too.
        for ($i = 0; $i < 120; $i += 2) InMemoryConfig::groupPolicy($this->config, sprintf('g%03d', $i), ['notes' => ['read']]);
        InMemoryConfig::groupPolicy($this->config, 'orphan', ['_system' => ['eligible']]);
        $first = $this->directory->page('', 1, 'granted');
        $this->assertCount(50, $first['groups']);
        $this->assertTrue($first['hasMore']);
        $this->assertSame(61, $first['total']);
        $this->assertSame(['all' => 121, 'granted' => 61, 'blocked' => null], $first['counts']);
        $this->assertSame('granted', $first['filter']);
        $this->assertSame(['g000', 'g002', 'g004'], array_slice(array_column($first['groups'], 'groupId'), 0, 3));
        $last = $this->directory->page('', 2, 'granted');
        $this->assertCount(11, $last['groups']);
        $this->assertFalse($last['hasMore']);
        $this->assertSame('orphan', $last['groups'][10]['groupId']);
        $searched = $this->directory->page('g01', 1, 'granted');
        $this->assertSame(['g010', 'g012', 'g014', 'g016', 'g018'], array_column($searched['groups'], 'groupId'));
        $this->assertSame(['all' => 10, 'granted' => 5, 'blocked' => null], $searched['counts'], 'counts follow the search');
        $all = $this->directory->page();
        $this->assertSame(121, $all['total']);
        $this->assertSame('all', $all['filter']);
    }

    public function testBlockedFilterKeepsOnlyGroupsThatCannotGrantAndClassifiesEachOnce(): void {
        $this->native = ['native-a' => [], 'native-b' => []];
        $this->ldap = ['ldap-a' => ['ana'], 'ldap-b' => []];
        // A group on both backends is not one Database group either, so it cannot grant.
        $this->native['both'] = $this->ldap['both'] = [];
        $this->sourceReads = 0;
        $blocked = $this->directory->page('', 1, 'blocked');
        $this->assertSame(['both', 'ldap-a', 'ldap-b'], array_column($blocked['groups'], 'groupId'));
        foreach ($blocked['groups'] as $row) $this->assertFalse($row['trusted']);
        $this->assertSame(['all' => 5, 'granted' => 0, 'blocked' => 3], $blocked['counts']);
        $this->assertSame(10, $this->sourceReads, 'one existence read per backend per searched group; the page reuses them');
        $this->assertSame(['ldap-a'], array_column($this->directory->page('ldap-a', 1, 'blocked')['groups'], 'groupId'));
        // An unreadable source is unknown, not blocked: it stays out of the filter and keeps trusted null in "all".
        $this->failingGid = 'ldap-b';
        $this->assertSame(['both', 'ldap-a'], array_column($this->directory->page('', 1, 'blocked')['groups'], 'groupId'));
        $rows = array_column($this->directory->page()['groups'], null, 'groupId');
        $this->assertNull($rows['ldap-b']['trusted']);
    }

    public function testUnknownFilterIsRefusedAsAnInvalidRequest(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->directory->page('', 1, 'everything');
    }

    public function testControllerPassesTheFilterAndAnswers400ForAnUnknownOne(): void {
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $session->method('getUser')->willReturn($user);
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('isAdmin')->willReturn(true);
        $this->native['team'] = [];
        InMemoryConfig::groupPolicy($this->config, 'team', ['notes' => ['read']]);
        $this->native['quiet'] = [];
        $controller = new GroupDirectoryController('mcp', $this->createMock(IRequest::class), $this->directory, $session, $manager);
        $this->assertSame(['team'], array_column($controller->index('', 1, 'granted')->getData()['groups'], 'groupId'));
        $this->assertSame(400, $controller->index('', 1, 'nope')->getStatus());
    }

    /** Scale guard for the list: 500 groups, one page of 50 drawn, and the cost of each filter. */
    public function testFiveHundredGroupsKeepAPageOfFiftyAndBoundedSourceReads(): void {
        for ($i = 0; $i < 450; ++$i) $this->native[sprintf('n%03d', $i)] = [];
        for ($i = 0; $i < 50; ++$i) $this->ldap[sprintf('x%03d', $i)] = [];
        for ($i = 0; $i < 450; $i += 9) InMemoryConfig::groupPolicy($this->config, sprintf('n%03d', $i), ['files' => ['read']]);
        $measure = function (string $filter): array {
            $this->sourceReads = 0;
            $start = hrtime(true);
            $page = $this->directory->page('', 1, $filter);
            return [$page, (hrtime(true) - $start) / 1e6, $this->sourceReads];
        };
        [$all, $allMs, $allReads] = $measure('all');
        [$granted, $grantedMs, $grantedReads] = $measure('granted');
        [$blocked, $blockedMs, $blockedReads] = $measure('blocked');
        $this->assertCount(50, $all['groups']);
        $this->assertSame(500, $all['total']);
        $this->assertSame(100, $allReads, 'only the 50 drawn groups are classified');
        $this->assertCount(50, $granted['groups']);
        $this->assertSame(50, $granted['total']);
        $this->assertSame(100, $grantedReads);
        $this->assertSame(50, $blocked['total']);
        $this->assertSame(1000, $blockedReads, 'blocked classifies each of the 500 groups once, and the page reuses it');
        fwrite(STDERR, sprintf("List 500 groups: all %.1f ms / %d source reads, granted %.1f ms / %d, blocked %.1f ms / %d (SQLite memory + directory doubles)\n",
            $allMs, $allReads, $grantedMs, $grantedReads, $blockedMs, $blockedReads));
    }

    public function testNoDirectoryReadRunsInsideATransaction(): void {
        $db = InMemoryConfig::groupDatabase($this->config)->connection();
        $db->beginTransaction();
        try {
            $this->expectException(PolicyUnavailable::class);
            $this->directory->page();
        } finally {
            $db->rollBack();
        }
    }

    public function testNonAdminIsRejectedBeforeResolutionAndAdminMayRead(): void {
        $directory = $this->createMock(GroupDirectory::class);
        $directory->expects($this->once())->method('page')->willReturn(['groups' => []]);
        $directory->expects($this->never())->method('detail');
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('someone');
        $session->method('getUser')->willReturn($user);
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('isAdmin')->willReturnOnConsecutiveCalls(false, false, true);
        $controller = new GroupDirectoryController('mcp', $this->createMock(IRequest::class), $directory, $session, $manager);
        $this->assertSame(403, $controller->index()->getStatus());
        $this->assertSame(403, $controller->detail('team')->getStatus());
        $this->assertSame(200, $controller->index()->getStatus());
    }
}
