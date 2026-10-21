<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Groups;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Access\Grants\Groups\GroupGrantsService;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The external phase of a group save (contracts §2.3): the fresh predicate, the member list and who of the given
 * credential holders loses its last source of eligibility when the group stops granting it. The resolution itself is
 * the production PolicyOperationContext over each holder's raw flags and group candidates. Validation T5 (other source
 * preserved).
 */
final class GroupGrantsServiceTest extends TestCase {
    private const GEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const GROUP = ['groupId' => 'staff', 'grants' => ['_system' => ['eligible']], 'trusted' => true, 'state' => 'active', 'gen' => self::GEN];

    /** @var array<string, array{raw:array, candidates:list<array>}> holder => what a resolution reads for it */
    private array $holders = [];
    /** @var list<array{string, list<string>}> resolutions asked: uid and the groups removed by overlay */
    private array $resolutions = [];

    private function service(?GroupSourceClassifier $classifier = null): GroupGrantsService {
        $writer = $this->createMock(PolicyTransaction::class);
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added, array $removed, bool $observe) use ($writer): PolicyOperationContext {
            $this->assertFalse($observe, 'the external phase of a writer command persists no observation');
            $this->resolutions[] = [$uid, $removed];
            $holder = $this->holders[$uid];
            $candidates = array_values(array_filter($holder['candidates'], static fn (array $c): bool => !in_array($c['groupId'], $removed, true)));
            return new PolicyOperationContext('0', $writer, $holder['raw'], $candidates, true, true, [], true, true,
                \OCA\Mcp\Access\ResourceScope\ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null));
        });
        if ($classifier === null) {
            $classifier = $this->createMock(GroupSourceClassifier::class);
            $classifier->method('trusted')->willReturn(true);
            $classifier->method('members')->willReturn([hash('sha256', 'ana') => 'ana']);
        }
        return new GroupGrantsService(\OCA\Mcp\Tests\Unit\InMemoryConfig::services((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this))->groups, $classifier, $resolver, new GroupGrantsMapper($this->createMock(\OCP\IDBConnection::class)), $this->createMock(GroupLifeMapper::class));
    }

    private function withdraw(array $holders): array {
        return $this->service()->admit(['gid' => 'staff', 'grants' => [], 'current' => ['_system' => ['eligible']],
            'withdraws' => true, 'holders' => $holders]);
    }

    public static function holders(): array {
        $eligible = ['present' => true, 'value' => '1'];
        $denied = ['present' => true, 'value' => '0'];
        $other = ['groupId' => 'team'] + self::GROUP;
        $suspended = ['groupId' => 'old', 'state' => 'needs_confirm', 'gen' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] + self::GROUP;
        return [
            'only through the group' => [[], [self::GROUP], true],
            'individual Allow' => [['eligible' => $eligible], [self::GROUP], false],
            'another granting group' => [[], [self::GROUP, $other], false],
            'already denied individually' => [['eligible' => $denied], [self::GROUP], false],
            'not a member of the group' => [[], [$other], false],
            'not a member, unknown through another group' => [[], [$suspended], false],
            // Unknown now only because the withdrawn group is itself suspended: determined without it.
            'only through the withdrawn group, itself suspended' => [[], [['state' => 'needs_confirm'] + self::GROUP], true],
        ];
    }

    #[DataProvider('holders')]
    public function testAHolderIsCutOnlyWhenTheGroupWasItsLastSource(array $raw, array $candidates, bool $cut): void {
        $this->holders['ana'] = ['raw' => $raw, 'candidates' => $candidates];
        $this->assertSame($cut ? ['ana'] : [], $this->withdraw(['ana'])['cuts']);
    }

    /**
     * Coordinator decision (15/10/2026): an unknown eligibility without the group is no cut; the save is unavailable
     * (503), so nothing is written and nobody is cut, until the other source is readable.
     */
    public function testAHolderUnknownWithoutTheGroupMakesTheSaveUnavailable(): void {
        $suspended = ['groupId' => 'old', 'state' => 'needs_confirm', 'gen' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] + self::GROUP;
        $this->holders = ['ana' => ['raw' => [], 'candidates' => [self::GROUP]], 'bob' => ['raw' => [], 'candidates' => [self::GROUP, $suspended]]];
        try {
            $this->withdraw(['ana', 'bob']);
            $this->fail('An unknown eligibility was decided');
        } catch (PolicyUnavailable $e) {
            $this->assertFalse($e->uncertain);
        }
    }

    /** F1: the lifecycle of every group the decisions read goes back to the writer, once per group. */
    public function testTheLifecyclesTheDecisionsReadAreReturnedOncePerGroup(): void {
        $other = ['groupId' => 'team', 'gen' => 'cccccccccccccccccccccccccccccccc'] + self::GROUP;
        $plain = ['groupId' => 'plain', 'state' => null, 'gen' => null] + self::GROUP;
        $this->holders = ['ana' => ['raw' => [], 'candidates' => [self::GROUP, $other]],
            'bob' => ['raw' => [], 'candidates' => [self::GROUP, $other, $plain]], 'carl' => ['raw' => [], 'candidates' => [$other]]];
        $admission = $this->withdraw(['ana', 'bob', 'carl']);
        $this->assertSame([], $admission['cuts'], 'team keeps ana and bob; carl is not a member');
        $this->assertSame([['groupId' => 'staff', 'state' => 'active', 'gen' => self::GEN],
            ['groupId' => 'team', 'state' => 'active', 'gen' => 'cccccccccccccccccccccccccccccccc'],
            ['groupId' => 'plain', 'state' => null, 'gen' => null]], $admission['sources']);
    }

    public function testOnlyAWithdrawalResolvesHolders(): void {
        $admission = $this->service()->admit(['gid' => 'staff', 'grants' => ['_system' => ['eligible']], 'current' => [],
            'withdraws' => false, 'holders' => []]);
        $this->assertSame(['trusted' => true, 'members' => ['ana'], 'cuts' => [], 'sources' => []], $admission);
        $this->assertSame([], $this->resolutions);
    }

    public function testANonMemberCostsOneResolutionAndAMemberTwo(): void {
        $this->holders = ['ana' => ['raw' => [], 'candidates' => [self::GROUP]], 'bob' => ['raw' => [], 'candidates' => []]];
        $this->assertSame(['ana'], $this->withdraw(['bob', 'ana'])['cuts']);
        $this->assertSame([['bob', []], ['ana', []], ['ana', ['staff']]], $this->resolutions);
    }

    public function testADirectoryFailureIsAFailureNeverAnEmptyGroup(): void {
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('trusted')->willReturn(true);
        $classifier->method('members')->willThrowException(new PolicyUnavailable('directory down'));
        $this->expectException(PolicyUnavailable::class);
        $this->service($classifier)->admit(['gid' => 'staff', 'grants' => [], 'current' => [], 'withdraws' => false, 'holders' => []]);
    }
}
