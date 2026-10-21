<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy\Events;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\OAuth\CredentialGeneration;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/** Cleanup payloads, the delivery of policy events after their commit and the global auth epoch, on SQLite. */
final class PolicyEventDeliveryTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyEventsMapper $events;
    private int $now = 1_760_000_000;

    protected function setUp(): void {
        $this->database = new SqliteDatabase($this, [new Version001100Date20261015000000($this->createMock(IDBConnection::class)), new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        (new PolicyStateSeeder($this->db))->seed();
        $this->events = new PolicyEventsMapper($this->db, $this->time());
    }

    private function time(): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        return $time;
    }

    private function append(string $id, string $kind, array $payload): void {
        $this->db->beginTransaction();
        $this->events->append($id, $kind, $payload);
        $this->db->commit();
    }

    /** @return array<string, array{status:string, attempts:int}> */
    private function statuses(): array {
        $rows = [];
        foreach ($this->database->dbal->fetchAllAssociative('SELECT id, status, attempts FROM mcp_policy_events ORDER BY id') as $row) {
            $rows[$row['id']] = ['status' => $row['status'], 'attempts' => (int)$row['attempts']];
        }
        return $rows;
    }

    public function testCleanupPayloadsHaveExactlyOneShapePerScope(): void {
        $this->assertSame(['v' => 1, 'scope' => 'user', 'uid' => 'alice', 'cut' => '7', 'epoch' => null], PolicyEventPayload::cleanupUser('alice', '7'));
        $this->assertSame(['v' => 1, 'scope' => 'global', 'uid' => null, 'cut' => null, 'epoch' => '3'], PolicyEventPayload::cleanupGlobal('3'));
        $this->assertSame(PolicyEventPayload::cleanupUser('bob', DecimalCounter::MAX), PolicyEventPayload::validate('cleanup', PolicyEventPayload::cleanupUser('bob', DecimalCounter::MAX)));
    }

    public static function invalidCleanups(): array {
        $user = ['v' => 1, 'scope' => 'user', 'uid' => 'alice', 'cut' => '7', 'epoch' => null];
        $global = ['v' => 1, 'scope' => 'global', 'uid' => null, 'cut' => null, 'epoch' => '3'];
        return [
            'user without cut' => [['cut' => null] + $user], 'user with an epoch' => [['epoch' => '1'] + $user],
            'leading zero' => [['cut' => '07'] + $user], 'negative' => [['cut' => '-1'] + $user], 'integer' => [['cut' => 7] + $user],
            'empty uid' => [['uid' => ''] + $user], 'global with a user' => [['uid' => 'alice'] + $global],
            'global without epoch' => [['epoch' => null] + $global], 'unknown scope' => [['scope' => 'client'] + $global],
            'extra field' => [$user + ['revision' => '1']], 'missing field' => [array_diff_key($user, ['epoch' => true])],
            'other version' => [['v' => 2] + $user], 'overflow' => [['epoch' => '9223372036854775808'] + $global],
        ];
    }

    #[DataProvider('invalidCleanups')]
    public function testAnyOtherCleanupPayloadIsRefused(array $payload): void {
        $this->expectException(UnexpectedValueException::class);
        PolicyEventPayload::validate('cleanup', $payload);
    }

    public function testTheRetryDelayDoublesFromAMinuteAndCapsEachStepAtAnHour(): void {
        $created = 1000;
        $delays = [];
        foreach ([0, 1, 2, 3, 6, 7, 8] as $attempts) {
            $delay = 0;
            while (!PolicyEventProcessor::due($attempts, $created, $created + $delay)) {
                $delay += 60;
            }
            $delays[$attempts] = $delay;
        }
        $this->assertSame([0 => 0, 1 => 60, 2 => 180, 3 => 420, 6 => 3780, 7 => 7380, 8 => 10980], $delays);
    }

    /** Delivery marks events delivered, retries failures with one more attempt and never delivers a receipt. */
    public function testDeliveryRetriesFailuresAndNeverTouchesReceipts(): void {
        $this->append(str_repeat('a', 32) . '-1', 'cleanup', PolicyEventPayload::cleanupUser('alice', '2'));
        $this->append(str_repeat('a', 32) . '-2', 'audit', PolicyEventPayload::audit('root', 'service', '0', '1', null, null,
            [['uid' => null, 'key' => 'service_enabled', 'before' => '1', 'after' => '0']]));
        $this->append(str_repeat('b', 32), 'receipt', PolicyEventPayload::receipt('root', 'grants.update', '1', '2',
            [['uid' => 'alice', 'key' => 'eligible', 'before' => null, 'after' => '1']]));
        $oauth = $this->createMock(OAuthStore::class);
        $oauth->expects($this->exactly(2))->method('deleteUpToGeneration')->with('alice', '2')
            ->willReturnOnConsecutiveCalls($this->throwException(new \RuntimeException('down')), null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');
        $processor = new PolicyEventProcessor($this->events, $oauth, $logger, $this->time());

        $this->assertSame(1, $processor->flush(10), 'the audit went through, the cleanup failed');
        $this->assertSame(['status' => 'pending', 'attempts' => 1], $this->statuses()[str_repeat('a', 32) . '-1']);
        $this->assertSame('delivered', $this->statuses()[str_repeat('a', 32) . '-2']['status']);
        $processor->flush(10); // not due yet: no second call
        $this->now += 60;
        $processor->flush(10);
        $this->assertSame('delivered', $this->statuses()[str_repeat('a', 32) . '-1']['status']);
        $this->assertSame(['status' => 'pending', 'attempts' => 0], $this->statuses()[str_repeat('b', 32)]);
    }

    public function testAfterCommitReportsAPendingCleanupWithoutThrowing(): void {
        $oauth = $this->createMock(OAuthStore::class);
        $oauth->method('deleteBelowEpoch')->willThrowException(new \RuntimeException('down'));
        $processor = new PolicyEventProcessor($this->events, $oauth, new NullLogger(), $this->time());
        $id = str_repeat('c', 32) . '-1';
        $this->append($id, 'cleanup', PolicyEventPayload::cleanupGlobal('1'));

        $this->assertFalse($processor->afterCommit([['id' => $id, 'kind' => 'cleanup', 'payload' => PolicyEventPayload::cleanupGlobal('1')]]));
        $this->assertTrue($processor->afterCommit([['id' => 'x', 'kind' => 'receipt', 'payload' => []]]), 'a receipt is not delivered');
        $this->assertSame(1, $this->statuses()[$id]['attempts']);
    }

    public function testThrowingDiagnosticLoggerCannotTurnACommittedCommandIntoFailure(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \RuntimeException('Log backend unavailable'));
        $logger->method('warning')->willThrowException(new \RuntimeException('Log backend unavailable'));
        $processor = new PolicyEventProcessor($this->events, $this->createMock(OAuthStore::class), $logger, $this->time());
        $state = new PolicyStateMapper($this->db);
        $transaction = new PolicyTransaction($this->db, $state, $this->events,
            $this->createMock(\OCP\Config\IUserConfig::class), $this->createMock(\OCP\IAppConfig::class), $processor);
        $result = $transaction->runInternal([], static fn () => null,
            static function ($prepared, \OCA\Mcp\Access\Policy\Transactions\PolicyAttempt $attempt): bool {
                $attempt->outbox()->add('cleanup', PolicyEventPayload::cleanupGlobal('1'));
                return true;
            });
        $this->assertSame('1', $state->state()['revision']);
        $this->assertTrue($result['cleanupPending']);
        $this->assertCount(1, $this->statuses());
        $id = array_key_first($this->statuses());
        $this->assertSame(['status' => 'pending', 'attempts' => 1], $this->statuses()[$id]);
        // Retry reaches the warning branch; neither logging level may escape delivery.
        $this->now += 100000;
        for ($attempt = 2; $attempt <= 6; ++$attempt) {
            $this->assertSame(0, $processor->flush());
            $this->assertSame(['status' => 'pending', 'attempts' => $attempt], $this->statuses()[$id]);
        }
    }

    public function testMaintenancePurgesExpiredReceiptsAndOldDeliveredEventsButNeverPendingOnes(): void {
        $receipt = PolicyEventPayload::receipt('root', 'grants.update', '0', '1', [['uid' => 'alice', 'key' => 'eligible', 'before' => null, 'after' => '1']]);
        $this->append(str_repeat('1', 32), 'receipt', $receipt);
        $this->append(str_repeat('2', 32), 'cleanup', PolicyEventPayload::cleanupUser('alice', '1'));
        $this->append(str_repeat('3', 32), 'audit', PolicyEventPayload::audit('root', 'service', '0', '1', null, null,
            [['uid' => null, 'key' => 'service_enabled', 'before' => null, 'after' => '1']]));
        $this->events->complete(str_repeat('3', 32));
        $this->assertFalse($this->events->complete(str_repeat('3', 32)), 'a second completion is a no-op');
        $oauth = $this->createMock(OAuthStore::class);
        $oauth->method('deleteUpToGeneration')->willThrowException(new \RuntimeException('down'));
        $oauth->expects($this->exactly(2))->method('purgeExpired');
        $processor = new PolicyEventProcessor($this->events, $oauth, new NullLogger(), $this->time());

        $this->now += \OCA\Mcp\Access\Policy\PolicyLimits::RECEIPT_TTL;
        $processor->maintenance();
        $this->assertArrayHasKey(str_repeat('1', 32), $this->statuses(), 'a receipt lives through its whole TTL');
        $this->now += PolicyEventProcessor::DELIVERED_KEPT;
        $processor->maintenance();

        $this->assertSame([str_repeat('2', 32)], array_keys($this->statuses()), 'only the failing cleanup is left, still pending');
        $this->assertSame('pending', $this->statuses()[str_repeat('2', 32)]['status']);
    }

    public function testTheGlobalEpochMovesOnlyUnderTheMutexAndNeverWraps(): void {
        $state = new PolicyStateMapper($this->db);
        try {
            $state->advanceAuthEpoch('0');
            $this->fail('Epoch moved outside a transaction');
        } catch (\LogicException) {
        }
        $this->db->beginTransaction();
        $this->assertSame('1', $state->advanceAuthEpoch('0'));
        try {
            $state->advanceAuthEpoch('0');
            $this->fail('A stale epoch moved it');
        } catch (PolicyUnavailable) {
        }
        $this->db->commit();
        $this->database->dbal->update('mcp_policy_state', ['auth_epoch' => DecimalCounter::MAX], ['id' => 1]);
        $this->db->beginTransaction();
        $this->expectException(PolicyUnavailable::class);
        try {
            $state->advanceAuthEpoch(DecimalCounter::MAX);
        } finally {
            $this->db->rollBack();
        }
    }





    public function testPendingOfReadsOnlyTheAskedKinds(): void {
        $this->append(str_repeat('d', 32), 'receipt', PolicyEventPayload::receipt('root', 'grants.update', '0', '1', [['uid' => 'alice', 'key' => 'eligible', 'before' => null, 'after' => '1']]));
        $this->append(str_repeat('e', 32), 'cleanup', PolicyEventPayload::cleanupUser('alice', '1'));
        $this->assertSame([str_repeat('e', 32)], array_column($this->events->pendingOf(['cleanup', 'audit'], 10), 'id'));
        $this->expectException(\InvalidArgumentException::class);
        $this->events->pendingOf(['intent'], 10);
    }

    /** Review F2: one invalid stored row is skipped and counted, never a reason to stop the whole queue. */
    public function testAnInvalidStoredEventIsSkippedAndCountedWithoutBlockingTheOthers(): void {
        $this->database->dbal->insert('mcp_policy_events', ['id' => str_repeat('0', 32), 'kind' => 'cleanup', 'status' => 'pending',
            'payload' => '{"v":1', 'created_at' => $this->now - 10, 'attempts' => 0]);
        $this->append(str_repeat('f', 32), 'cleanup', PolicyEventPayload::cleanupUser('alice', '1'));
        $oauth = $this->createMock(OAuthStore::class);
        $oauth->expects($this->once())->method('deleteUpToGeneration')->with('alice', '1');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Skipping an invalid stored policy event', $this->anything());

        $this->assertSame(1, (new PolicyEventProcessor($this->events, $oauth, $logger, $this->time()))->flush(10));
        $this->assertSame(['status' => 'pending', 'attempts' => 1], $this->statuses()[str_repeat('0', 32)]);
        $this->assertSame('delivered', $this->statuses()[str_repeat('f', 32)]['status']);
        $invalid = null;
        $this->events->pendingOf(['cleanup'], 10, $invalid);
        $this->assertSame([str_repeat('0', 32)], $invalid);
    }

    /** Review F2: events that keep failing come after fresh ones, so a full batch of them never starves the queue. */
    public function testEventsThatKeepFailingNeverHideAFreshOne(): void {
        foreach (range(1, 3) as $n) {
            $this->database->dbal->insert('mcp_policy_events', ['id' => str_repeat((string)$n, 32), 'kind' => 'cleanup', 'status' => 'pending',
                'payload' => json_encode(PolicyEventPayload::cleanupUser('bob', '1')), 'created_at' => $this->now - 1000, 'attempts' => 9]);
        }
        $this->append(str_repeat('9', 32), 'cleanup', PolicyEventPayload::cleanupUser('alice', '1'));
        $oauth = $this->createMock(OAuthStore::class);
        $oauth->expects($this->once())->method('deleteUpToGeneration')->with('alice', '1');

        $this->assertSame(1, (new PolicyEventProcessor($this->events, $oauth, new NullLogger(), $this->time()))->flush(3));
    }

    /** Review F2: the job stops at the first batch that delivers nothing instead of rereading it twenty times. */
    public function testTheJobStopsAtABatchThatDeliversNothing(): void {
        foreach (range(1, PolicyEventProcessor::JOB_BATCH) as $n) {
            $this->database->dbal->insert('mcp_policy_events', ['id' => sprintf('%032x', $n), 'kind' => 'cleanup', 'status' => 'pending',
                'payload' => json_encode(PolicyEventPayload::cleanupUser('bob', '1')), 'created_at' => $this->now, 'attempts' => 3]);
        }
        $reads = 0;
        $events = new class($this->db, $this->time(), $reads) extends PolicyEventsMapper {
            public function __construct(IDBConnection $db, ITimeFactory $time, private int &$reads) { parent::__construct($db, $time); }
            public function pendingOf(array $kinds, int $limit, ?array &$invalid = null): array {
                ++$this->reads;
                return parent::pendingOf($kinds, $limit, $invalid);
            }
        };
        (new PolicyEventProcessor($events, $this->createMock(OAuthStore::class), new NullLogger(), $this->time()))->maintenance();
        $this->assertSame(1, $reads, 'none of them is due yet');
    }
}
