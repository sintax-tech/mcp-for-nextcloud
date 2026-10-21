<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth\Revocation;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\Revocation\CauseClassifier;
use OCA\Mcp\OAuth\Revocation\CauseProcessor;
use OCA\Mcp\OAuth\Revocation\DeferredQueue;
use OCA\Mcp\Access\Grants\Groups\GroupLifecycle;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Resolution\PolicyOperationContext;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Tests\Support\Access\Policy\PolicyServices;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;

/**
 * The cause protocol of contracts §3.3.1/§3.3.4 and the deferred work of §3.3.6, on SQLite in memory: the writer, the
 * recheck, generation and state mappers and the OAuth store are the production classes on one real connection; the
 * account and the resolved eligibility are doubles the test sets per user. T7 (a)–(e) and T8 (F2, Q1 (i)–(vi),
 * undated exception), unit part.
 */
final class CauseProcessingTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyServices $writer;
    private RecheckMapper $rechecks;
    private AuthUsersMapper $authUsers;
    private PolicyStateMapper $state;
    private CauseClassifier $classifier;
    private CauseProcessor $processor;
    private DeferredQueue $queue;
    /** @var list<callable> shutdown callbacks the queue would have registered */
    private array $shutdowns = [];
    /** @var list<array{string, string, array}> */
    private array $logs = [];
    private int $now = 1_760_000_000;
    /** @var array<string, bool> uid => enabled; absent = no account */
    private array $accounts = ['alice' => true];
    /** @var \Closure(string, list<string>): ?bool resolved eligibility of a user without the given groups */
    private \Closure $eligibility;
    /** @var (\Closure(): void)|null runs once inside the next eligibility read, in the external phase */
    private ?\Closure $duringRead = null;
    private int $reads = 0;
    private InMemoryAppConfig $apps;
    private GroupLifecycle $groups;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version000500Date20260930000000(), new Version000700Date20261001000000(),
            new Version000800Date20261002000000(), new Version000800Date20261002010000(), new Version001002Date20261008000000(),
            new Version001100Date20261015000000($this->createMock(IDBConnection::class)), new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        (new PolicyStateSeeder($this->db))->seed();
        $this->eligibility = static fn (string $uid, array $without): ?bool => true;
        $data = new InMemoryConfig();
        $config = $data->mock($this);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!array_key_exists($uid, $this->accounts)) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn($this->accounts[$uid]);
            return $user;
        });
        $this->state = new PolicyStateMapper($this->db);
        $this->authUsers = new AuthUsersMapper($this->db);
        $this->rechecks = new RecheckMapper($this->db);
        $events = new PolicyEventsMapper($this->db, $time);
        $logger = $this->logger();
        $this->writer = new PolicyServices(new PolicyTransaction($this->db, $this->state, $events, $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), new PolicyEventProcessor($events, new OAuthStore($this->db), $logger, $time)),
            $config, $this->createMock(IAppConfig::class), $users, $time, $this->state, $events, $this->authUsers, $this->rechecks, new \OCA\Mcp\Db\GroupGrantsMapper($this->db), new \OCA\Mcp\Db\GroupLifeMapper($this->db), new \OCA\Mcp\OAuth\OAuthStore($this->db), new \OCA\Mcp\Db\ResourceScopeMapper($this->db));
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added = [], array $removed = [], bool $observe = true): PolicyOperationContext {
            $this->assertFalse($observe, 'cause classification never persists an observation');
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            ++$this->reads;
            if ($this->duringRead !== null) {
                [$hook, $this->duringRead] = [$this->duringRead, null];
                $hook();
            }
            return self::context($this->writer, ($this->eligibility)($uid, $removed));
        });
        $this->classifier = new CauseClassifier($users, $resolver);
        $this->queue = new class($this->shutdowns) extends DeferredQueue {
            public function __construct(private array &$registered) {}
            protected function register(callable $onShutdown): void { $this->registered[] = $onShutdown; }
        };
        $this->apps = new InMemoryAppConfig();
        $this->groups = $this->createMock(GroupLifecycle::class);
        $this->processor = new CauseProcessor($this->writer->credentials, $this->rechecks, $this->classifier, $this->queue, $this->db, $logger,
            $this->apps->worker($this), $this->groups);
    }

    private function logger(): AbstractLogger {
        return new class($this->logs) extends AbstractLogger {
            public function __construct(private array &$logs) {}
            public function log($level, string|\Stringable $message, array $context = []): void { $this->logs[] = [(string)$level, (string)$message, $context]; }
        };
    }

    /** A context whose only answer that matters here is eligible(): true, false, or unknown (null). */
    private static function context(PolicyServices $writer, ?bool $eligible): PolicyOperationContext {
        $raw = ['eligible' => $eligible === null ? ['present' => false] : ['present' => true, 'value' => $eligible ? '1' : '0']];
        $candidates = $eligible === null ? [['groupId' => 'lost', 'grants' => ['_system' => ['eligible']], 'trusted' => false, 'state' => 'active']] : [];
        return new PolicyOperationContext('0', $writer->transaction, $raw, $candidates, true, true, [], true, true, // No account: the guard is unrestricted and never reads its mapper.
                \OCA\Mcp\Access\ResourceScope\ScopeGuard::read((new \ReflectionClass(\OCA\Mcp\Db\ResourceScopeMapper::class))->newInstanceWithoutConstructor(), null));
    }

    private static function gen(string $seed): string {
        return substr(hash('sha256', $seed), 0, 32);
    }

    private function record(string $reason, ?string $gid, string $gen, string $uid = 'alice'): void {
        $this->rechecks->record($uid, $reason, $gid, $gen, $this->now);
    }

    /** Issues one generation for alice, as a consent does, under the writer's mutex. */
    private function issue(): string {
        $generation = '';
        $this->writer->transaction->underMutex(function () use (&$generation): void {
            $generation = $this->authUsers->issue('alice');
        });
        return $generation;
    }

    private function keys(string $uid = 'alice'): array {
        return array_map(static fn (array $c): string => $c['reason'] . ':' . ($c['groupId'] ?? '') . ':' . $c['gen'], $this->rechecks->causes($uid));
    }

    public function testClassificationFollowsTheContractTable(): void {
        $account = ['causeKey' => IdentityKey::accountCause(), 'reason' => RecheckMapper::REASON_ACCOUNT, 'groupId' => null, 'gen' => self::gen('a'), 'createdAt' => '0'];
        $group = ['causeKey' => IdentityKey::group('g1'), 'reason' => RecheckMapper::REASON_GROUP, 'groupId' => 'g1', 'gen' => self::gen('g'), 'createdAt' => '0'];
        $cases = [
            'account missing' => [null, static fn () => true, [true, true]],
            'account disabled' => [false, static fn () => true, [true, true]],
            'not eligible' => [true, static fn () => false, [true, true]],
            'eligible: Q1 for the account, no cut for another source' => [true, static fn () => true, [true, false]],
            'eligible only through g1: Q1 for both' => [true, static fn (string $uid, array $without) => $without === [], [true, true]],
        ];
        foreach ($cases as $label => [$enabled, $eligibility, $cuts]) {
            $this->accounts = $enabled === null ? [] : ['alice' => $enabled];
            $this->eligibility = $eligibility;
            $this->assertSame([$account['causeKey'] => $cuts[0], $group['causeKey'] => $cuts[1]],
                $this->classifier->classify('alice', [$account, $group]), $label);
        }
        $this->accounts = ['alice' => true];
        foreach ([static fn () => null, static fn (string $uid, array $without) => $without === [] ? true : null] as $unknown) {
            $this->eligibility = $unknown;
            try {
                $this->classifier->classify('alice', [$group]);
                $this->fail('Unknown eligibility classified');
            } catch (PolicyUnavailable) {
            }
        }
        // Undated (§6.2.1): an eligible user is never cut; a lost one is.
        $this->eligibility = static fn () => true;
        $this->assertFalse($this->classifier->undated('alice'));
        $this->eligibility = static fn () => false;
        $this->assertTrue($this->classifier->undated('alice'));
        $this->accounts = [];
        $this->assertTrue($this->classifier->undated('alice'));
    }

    public function testAfterConsumesOnlyItsOwnCauseWithItsGenAndCutsOnce(): void {
        $old = $this->issue();
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc'));
        $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g1'));
        $this->record(RecheckMapper::REASON_GROUP, 'g2', self::gen('g2'));
        $revision = $this->state->state()['revision'];
        // After of g1, user no longer eligible without it: cut at issue_seq, only g1 consumed (T8).
        $this->eligibility = static fn (string $uid, array $without) => !in_array('g1', $without, true);
        $this->processor->after('alice', IdentityKey::group('g1'), self::gen('g1'));
        $this->assertSame($old, $this->authUsers->generations('alice')['revoked_below']);
        $this->assertSame(['account_delete::' . self::gen('acc'), 'group_removal:g2:' . self::gen('g2')], self::sorted($this->keys()));
        $this->assertSame(DecimalCounter::increment($revision), $this->state->state()['revision']);
        // Its duplicate After, and an After with a gen replaced by a newer operation, are no-ops.
        $this->processor->after('alice', IdentityKey::group('g1'), self::gen('g1'));
        $this->record(RecheckMapper::REASON_GROUP, 'g2', self::gen('g2-new'));
        $this->processor->after('alice', IdentityKey::group('g2'), self::gen('g2'));
        $this->assertSame(DecimalCounter::increment($revision), $this->state->state()['revision']);
        $this->assertContains('group_removal:g2:' . self::gen('g2-new'), $this->keys());
    }

    public function testRemovalWithAnotherSourceIsConsumedWithoutCut(): void {
        $this->issue();
        $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g1'));
        $this->processor->after('alice', IdentityKey::group('g1'), self::gen('g1'));
        $this->assertSame([], $this->rechecks->causes('alice'));
        $this->assertSame('0', $this->authUsers->generations('alice')['revoked_below']);
    }

    public function testUnknownEligibilityKeepsTheCauseAndRevokesNothing(): void {
        $this->issue();
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc'));
        $this->eligibility = static fn () => null;
        try {
            $this->processor->preflight('alice');
            $this->fail('Unknown eligibility answered');
        } catch (PolicyUnavailable) {
        }
        $this->assertCount(1, $this->rechecks->causes('alice'));
        $this->assertSame('0', $this->authUsers->generations('alice')['revoked_below']);
        $this->assertSame(0, $this->processor->reconcile());
        $this->assertCount(1, $this->rechecks->causes('alice'));
    }

    /** F2: a Before records a new gen between the phases; the set differs from R0, so the attempt is replayed. */
    public function testANewGenRecordedBetweenThePhasesReplaysOutsideTheLock(): void {
        $this->issue();
        $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g'));
        $this->eligibility = static fn () => true;
        $this->duringRead = fn () => $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g-new'));
        // A job that classified g: under the mutex the set is not R0, the replay reads g-new, and g-new gets Q1 now.
        $this->eligibility = static fn (string $uid, array $without) => $without === [];
        $this->assertSame(1, $this->writer->credentials->processCauses('alice', $this->processor->classifier()));
        $this->assertGreaterThanOrEqual(4, $this->reads, 'two classifications of two reads: the replay ran outside the lock');
        $this->assertSame([], $this->rechecks->causes('alice'));
        $this->assertSame('2', $this->authUsers->generations('alice')['revoked_below']);
        // The After of the old operation, selected by its gen, now finds nothing.
        $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g-third'));
        $this->duringRead = fn () => $this->record(RecheckMapper::REASON_GROUP, 'g1', self::gen('g-fourth'));
        $this->processor->after('alice', IdentityKey::group('g1'), self::gen('g-third'));
        $this->assertSame(['group_removal:g1:' . self::gen('g-fourth')], $this->keys());
    }

    /** T7 (a)/(e), T8 (v): issuance consumes the pending cause with its cut; the older cause's After is then a no-op. */
    public function testConsentConsumesThePendingCauseBeforeIssuingAndOldAftersAreNoOps(): void {
        $old = $this->issue();
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc'));
        $this->eligibility = static fn () => true;
        $issued = null;
        // The consent of the writer, as TokenService calls it, but with the eligibility read through the double.
        $this->writer->credentials->runCredential('alice', fn () => null, function ($snapshot, array $state, array $generations) use (&$issued): bool {
            $this->assertSame($generations['issue_seq'], $generations['revoked_below'], 'the cut happened before the issue');
            $issued = $this->authUsers->issue('alice');
            return false;
        }, $this->processor->classifier());
        $this->assertSame($old, $this->authUsers->generations('alice')['revoked_below']);
        $this->assertSame(1, \OCA\Mcp\Access\Policy\DecimalCounter::compare((string)$issued, $old));
        $this->assertSame([], $this->rechecks->causes('alice'));
        // The After of the consumed cause, a retry and the job: nothing to do, the new generation stays valid.
        $this->processor->after('alice', IdentityKey::accountCause(), self::gen('acc'));
        $this->processor->reconcile();
        $this->assertSame($old, $this->authUsers->generations('alice')['revoked_below']);
    }

    /** T8 (v): the job read (cause, g); a consent consumes it and issues; the job under the mutex finds no g. */
    public function testJobThatLosesTheRaceToAConsentCutsNothing(): void {
        $this->issue();
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc'));
        $issued = null;
        $this->duringRead = function () use (&$issued): void {
            $this->writer->credentials->runCredential('alice', fn () => null, function () use (&$issued): bool {
                $issued = $this->authUsers->issue('alice');
                return false;
            }, fn (string $uid, array $causes): array => array_fill_keys(array_column($causes, 'causeKey'), true));
        };
        $this->assertSame(0, $this->processor->reconcile());
        $generations = $this->authUsers->generations('alice');
        $this->assertSame($issued, $generations['issue_seq']);
        $this->assertSame(1, \OCA\Mcp\Access\Policy\DecimalCounter::compare($issued, $generations['revoked_below']));
        // T8 (vi): a new native operation after the issuance records a new gen, and that one cuts.
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc-2'));
        $this->assertSame(1, $this->processor->reconcile());
        $this->assertSame($issued, $this->authUsers->generations('alice')['revoked_below']);
    }

    public function testReconcilerProcessesEveryPendingUserAndOneFailureStopsNoOther(): void {
        $this->accounts = ['alice' => true, 'bob' => true];
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('a'), 'alice');
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('b'), 'bob');
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('c'), 'carol');
        $this->eligibility = static fn (string $uid) => $uid === 'bob' ? null : true;
        $this->assertSame(['alice', 'bob', 'carol'], self::sorted($this->rechecks->pendingUsers(10)));
        $this->assertSame(2, $this->processor->reconcile());
        $this->assertSame([], $this->rechecks->causes('alice'));
        $this->assertCount(1, $this->rechecks->causes('bob'));
        $this->assertSame([], $this->rechecks->causes('carol'));
        $this->assertSame(['bob'], $this->rechecks->pendingUsers(10));
    }

    /** Review F4: users whose causes stay unclassifiable cannot hold every batch; the cursor moves past them. */
    public function testTheReconcilerCursorMovesPastUsersItCannotClassify(): void {
        $uids = ['u1', 'u2', 'u3'];
        usort($uids, static fn (string $a, string $b): int => strcmp(IdentityKey::user($a), IdentityKey::user($b)));
        $this->accounts = array_fill_keys($uids, true);
        foreach ($uids as $uid) {
            $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen($uid), $uid);
        }
        // The two first users by user_key are stuck (unknown eligibility); only the last one can be classified.
        $last = $uids[2];
        $this->eligibility = static fn (string $uid): ?bool => $uid === $last ? true : null;
        $this->assertSame(0, $this->processor->reconcile(2));
        $this->assertSame(IdentityKey::user($uids[1]), $this->apps->stored['mcp'][CauseProcessor::CURSOR_KEY]);
        $this->assertSame(1, $this->processor->reconcile(2), 'the next run starts after the stuck users');
        $this->assertSame([], $this->rechecks->causes($last));
        $this->assertSame('', $this->apps->stored['mcp'][CauseProcessor::CURSOR_KEY], 'a short page wraps around');
        $this->assertSame(0, $this->processor->reconcile(2));
        $this->assertCount(1, $this->rechecks->causes($uids[0]));
    }

    /** §3.3.6 / §6.2.1: no query inside a caller's transaction; undated causes never cut an eligible user. */
    public function testDeferredWorkWaitsForASafePointAndNeverCutsAnEligibleUserWithoutADate(): void {
        $old = $this->issue();
        $this->db->beginTransaction();
        $this->processor->deferUndated('alice');
        $this->processor->flush();
        $this->assertFalse($this->queue->isEmpty(), 'nothing runs inside the caller transaction');
        $this->db->rollBack();
        $this->eligibility = static fn () => true;
        $this->processor->flush();
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame('0', $this->authUsers->generations('alice')['revoked_below']);
        $this->assertSame('deferred_unconfirmed', $this->logs[array_key_last($this->logs)][1]);
        // Commit with the user lost: the cut at the issue_seq read under the mutex.
        $this->processor->deferUndated('alice');
        $this->eligibility = static fn () => false;
        $this->processor->flush();
        $this->assertSame($old, $this->authUsers->generations('alice')['revoked_below']);
        $this->assertCount(1, $this->shutdowns, 'one shutdown callback per process');
    }

    public function testUndatedReconciliationReclassifiesWhenFreshConsentWinsTheRace(): void {
        $old = $this->issue();
        $fresh = null;
        $this->eligibility = function () use (&$fresh): bool {
            // Classification observed a denial, then access and consent changed before its cut.
            $this->eligibility = static fn (): bool => true;
            $fresh = $this->issue();
            return false;
        };
        $this->processor->deferUndated('alice');
        $this->processor->flush();
        $this->assertNotSame($old, $fresh);
        $this->assertSame($fresh, $this->authUsers->generations('alice')['issue_seq']);
        $this->assertSame('0', $this->authUsers->generations('alice')['revoked_below']);
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame(2, $this->reads, 'changed generations require fresh external classification');
    }

    public function testDeferredAfterOfAStoredCauseKeepsQ1(): void {
        $old = $this->issue();
        $this->record(RecheckMapper::REASON_ACCOUNT, null, self::gen('acc'));
        $this->db->beginTransaction();
        $this->processor->after('alice', IdentityKey::accountCause(), self::gen('acc'));
        $this->db->commit();
        $this->assertCount(1, $this->rechecks->causes('alice'));
        $this->processor->flush();
        $this->assertSame([], $this->rechecks->causes('alice'));
        $this->assertSame($old, $this->authUsers->generations('alice')['revoked_below']);
    }

    public function testShutdownDropsWithLogsAndContainsEveryFailureWithoutOutput(): void {
        $this->expectOutputString('');
        $this->processor->deferUndated('alice');
        $this->db->beginTransaction();
        $this->shutdowns[0]();
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame('deferred_dropped_open_transaction', $this->logs[array_key_last($this->logs)][1]);
        $this->db->rollBack();
        // Unreadable state: kept at the safe point, dropped at shutdown.
        $this->eligibility = static fn () => null;
        $this->processor->deferUndated('alice');
        $this->processor->flush();
        $this->assertFalse($this->queue->isEmpty());
        $this->shutdowns[0]();
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame('deferred_dropped_unavailable', $this->logs[array_key_last($this->logs)][1]);
        // Any Throwable of the processing is contained, and the process goes on.
        $this->eligibility = static fn () => throw new \Error('bug');
        $this->processor->deferUndated('alice');
        $this->processor->flush();
        $this->shutdowns[0]();
        $this->assertTrue($this->queue->isEmpty());
        $this->assertSame('0', $this->authUsers->generations('alice')['revoked_below']);
    }

    private static function sorted(array $values): array {
        sort($values, SORT_STRING);
        return $values;
    }
}
