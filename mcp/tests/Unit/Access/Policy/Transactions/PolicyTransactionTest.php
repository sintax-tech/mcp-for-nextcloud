<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy\Transactions;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\Events\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyTransactionTest extends TestCase {
    private SqliteDatabase $database;
    private IDBConnection $db;
    private PolicyStateMapper $state;
    private PolicyTransaction $writer;

    protected function setUp(): void {
        $migration = new Version001100Date20261015000000($this->createMock(IDBConnection::class));
        $this->database = new SqliteDatabase($this, [$migration, new Version001100Date20261015010000()]);
        $this->db = $this->database->connection();
        $this->state = new PolicyStateMapper($this->db);
        (new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        // An app-owned scratch table models all local writes (groups, epochs and outbox) without core SQL.
        $this->database->dbal->executeStatement('CREATE TABLE unit_policy (id INTEGER PRIMARY KEY, value TEXT)');
        $this->writer = $this->writer();
    }

    private function events(?IDBConnection $db = null): PolicyEventsMapper {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);
        return new PolicyEventsMapper($db ?? $this->db, $time);
    }

    private function processor(): PolicyEventProcessor {
        return new PolicyEventProcessor($this->events(), new \OCA\Mcp\OAuth\OAuthStore($this->db), new \Psr\Log\NullLogger(), $this->createMock(ITimeFactory::class));
    }

    private function writer(?PolicyStateMapper $state = null, ?IUserConfig $users = null, ?IAppConfig $apps = null): PolicyTransaction {
        return new PolicyTransaction($this->db, $state ?? $this->state, $this->events(),
             $users ?? $this->createMock(IUserConfig::class),
            $apps ?? $this->createMock(IAppConfig::class), $this->processor());
    }



    private function write(int $id): \Closure {
        return function () use ($id): bool {
            $this->database->dbal->insert('unit_policy', ['id' => $id, 'value' => 'new']);
            return true;
        };
    }

    public function testStaleWorkerAndAbaOnDifferentTargetsCannotOverwriteCommittedState(): void {
        $stale = $this->state->state()['revision'];
        $this->assertSame('1', $this->writer->runAdmin('0', [], fn () => null, $this->write(1))['revision']);
        $this->writer->runAdmin('1', [], fn () => null, $this->write(2));
        try {
            $this->writer->runAdmin($stale, [], fn () => null, $this->write(3));
            $this->fail('Stale revision accepted');
        } catch (PolicyConflict $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame([1, 2], array_column($this->database->rows('unit_policy'), 'id'));
        $this->assertSame('2', $this->state->state()['revision']);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testExternalObservationRunsOutsideTransactionAndRetriesWithTheSameCommandId(): void {
        $ids = [];
        $attempts = 0;
        $result = $this->writer->runInternal([], function ($state, $id) use (&$ids): string {
            $this->assertFalse($this->db->inTransaction());
            $ids[] = $id;
            return $state['revision'];
        }, function ($snapshot) use (&$attempts): bool {
            $this->assertTrue($this->db->inTransaction());
            $this->assertSame('0', $snapshot);
            $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
            if (++$attempts < 3) {
                throw new SnapshotInvalidated();
            }
            return true;
        });
        $this->assertCount(3, $ids);
        $this->assertCount(1, array_unique($ids));
        $this->assertSame($ids[0], $result['commandId']);
        $this->assertCount(1, $this->database->rows('unit_policy'));
        $this->assertSame(['revision' => '1', 'lock_seq' => '1', 'auth_epoch' => '0'], $this->state->state());
    }

    public function testRevisionChangedBetweenPhasesRepeatsAdmissionBeforeAnyLocalMutation(): void {
        $attempts = 0;
        $result = $this->writer->runInternal([], function () use (&$attempts): void {
            if (++$attempts === 1) {
                $this->writer->runInternal([], fn () => null, $this->write(1));
            }
        }, $this->write(2));
        $this->assertSame(2, $attempts);
        $this->assertSame('2', $result['revision']);
    }

    public function testDirectoryObservationWithoutAppWriteDoesNotAdvanceRevision(): void {
        $result = $this->writer->runInternal([], fn () => 'directory changed', fn () => false);
        $this->assertFalse($result['changed']);
        $this->assertSame('0', $result['revision']);
        $this->assertSame('1', $this->writer->runInternal([], fn () => 'processed event', $this->write(1))['revision']);
    }

    public function testCasFailureRollsBackEveryLocalWriteAndMutex(): void {
        $state = $this->createMock(PolicyStateMapper::class);
        $state->method('state')->willReturn(['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0']);
        $state->method('compareAndSet')->willThrowException(new PolicyConflict());
        try {
            $this->writer($state)->runAdmin('0', [], fn () => null, $this->write(1));
            $this->fail('CAS failure accepted');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->database->rows('unit_policy'));
        $this->assertSame('0', $this->state->state()['lock_seq']);
    }

    #[DataProvider('retryReasons')]
    public function testRetryableDatabaseErrorsRollbackFullyAndStopAfterTwoRetries(int $reason): void {
        $error = new class($reason) extends DatabaseException {
            public function __construct(private int $reason) { parent::__construct('injected'); }
            public function getReason(): ?int { return $this->reason; }
        };
        $attempts = 0;
        $started = hrtime(true);
        try {
            $this->writer->runInternal([], fn () => null, function () use (&$attempts, $error): bool {
                ++$attempts;
                $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
                throw $error;
            });
            $this->fail('Retry exhaustion accepted');
        } catch (PolicyUnavailable $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertSame(3, $attempts);
        $this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
        $this->assertSame([], $this->database->rows('unit_policy'));
        $this->assertSame('0', $this->state->state()['lock_seq']);
    }

    public static function retryReasons(): array {
        return [[DatabaseException::REASON_DEADLOCK], [DatabaseException::REASON_LOCK_WAIT_TIMEOUT]];
    }

    public function testForeignTransactionIsRefusedWithoutAnyStatementOrRollback(): void {
        $this->db->beginTransaction();
        $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'owner']);
        try {
            $this->writer->runInternal([], fn () => $this->fail('Admission called'), $this->write(2));
            $this->fail('Foreign transaction accepted');
        } catch (PolicyUnavailable) {
        }
        $this->assertTrue($this->db->inTransaction());
        $this->db->commit();
        $this->assertCount(1, $this->database->rows('unit_policy'));
    }

    public function testMissingStateFailsClosedAndSeedNeverOverwritesExistingRevision(): void {
        $this->writer->runInternal([], fn () => null, $this->write(1));
        (new Version001100Date20261015000000($this->db))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        $this->assertSame('1', $this->state->state()['revision']);
        $this->database->dbal->delete('mcp_policy_state', ['id' => 1]);
        $this->expectException(PolicyUnavailable::class);
        $this->writer->runInternal([], fn () => null, $this->write(2));
    }

    public function testExhaustedMutexAndRevisionRollbackRatherThanWrappingCounters(): void {
        foreach (['lock_seq', 'revision'] as $counter) {
            $this->database->dbal->update('mcp_policy_state', ['revision' => '0', 'lock_seq' => '0', $counter => DecimalCounter::MAX], ['id' => 1]);
            try {
                $this->writer->runInternal([], fn () => null, $this->write(1));
                $this->fail('Exhausted counter accepted');
            } catch (PolicyUnavailable) {
            }
            $this->assertSame([], $this->database->rows('unit_policy'));
            $this->assertSame(DecimalCounter::MAX, $this->state->state()[$counter]);
        }
    }





    public function testUnknownCommitOutcomeIsNeverReplayedAndAbandonsContext(): void {
        $commits = 0;
        $db = $this->boundaryConnection(function () use (&$commits): void {
            ++$commits;
            $this->db->commit(); // The server committed, but the client lost its acknowledgement.
            throw new \RuntimeException('connection lost');
        });
        $writer = $this->writerOn($db);
        try {
            $writer->runInternal([], fn () => null, $this->write(1));
            $this->fail('Unknown commit accepted');
        } catch (PolicyUnavailable $e) {
            $this->assertTrue($e->uncertain);
        }
        $this->assertSame(1, $commits);
        $this->assertCount(1, $this->database->rows('unit_policy'));
        $this->assertSame('1', $this->state->state()['revision']);
        $this->expectException(PolicyUnavailable::class);
        $writer->runInternal([], fn () => $this->fail('Abandoned writer reused'), $this->write(2));
    }

    public function testKnownCommitDeadlockRollsBackBeforeRetryingTheEntireAdmission(): void {
        $commits = 0;
        $db = $this->boundaryConnection(function () use (&$commits): void {
            if (++$commits === 1) {
                throw new class('deadlock') extends DatabaseException {
                    public function getReason(): ?int { return self::REASON_DEADLOCK; }
                };
            }
            $this->db->commit();
        });
        $result = $this->writerOn($db)->runInternal([], fn () => null, $this->write(1));
        $this->assertSame(2, $commits);
        $this->assertSame('1', $result['revision']);
        $this->assertCount(1, $this->database->rows('unit_policy'));
        $this->assertSame('1', $this->state->state()['lock_seq']);
    }

    public function testUnwrappedSqliteBusyIsRetriedButOrdinaryDriverErrorIsNot(): void {
        foreach ([5 => 3, 1 => 1] as $code => $expectedAttempts) {
            $attempts = 0;
            $error = new class('sqlite driver', $code) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): ?string { return 'HY000'; }
            };
            try {
                $this->writer->runInternal([], fn () => null, function () use ($error, &$attempts): bool {
                    ++$attempts;
                    $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
                    throw $error;
                });
                $this->fail('Driver error accepted');
            } catch (PolicyUnavailable) {
            }
            $this->assertSame($expectedAttempts, $attempts);
            $this->assertSame([], $this->database->rows('unit_policy'));
        }
    }

    /**
     * PostgreSQL lock_timeout (SQLSTATE 55P03) reaches the writer as a core database exception with no reason, wrapping
     * the Doctrine and driver errors; found by the real lock-wait proof of T19. It is a lock wait: replayed, at most
     * twice. A wrapped ordinary error (unique violation, 23505) is not.
     */
    public function testAWrappedLockTimeoutWithoutAReasonIsRetriedButAWrappedOrdinaryErrorIsNot(): void {
        foreach ([['55P03', 3], ['23505', 1]] as [$sqlState, $expectedAttempts]) {
            $driver = new class('driver', 0, $sqlState) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                public function __construct(string $message, int $code, private string $state) { parent::__construct($message, $code); }
                public function getSQLState(): ?string { return $this->state; }
            };
            $doctrine = new \Doctrine\DBAL\Exception\DriverException($driver, null);
            $core = new class('wrapped', 0, $doctrine) extends DatabaseException {
                public function getReason(): ?int { return null; }
            };
            $attempts = 0;
            try {
                $this->writer->runInternal([], fn () => null, function () use ($core, &$attempts): bool {
                    ++$attempts;
                    $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
                    throw $core;
                });
                $this->fail('Wrapped driver error accepted');
            } catch (PolicyUnavailable) {
            }
            $this->assertSame($expectedAttempts, $attempts, $sqlState);
            $this->assertSame([], $this->database->rows('unit_policy'));
        }
    }

    private function boundaryConnection(\Closure $commit): IDBConnection {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => $this->db->getQueryBuilder());
        $db->method('inTransaction')->willReturnCallback(fn () => $this->db->inTransaction());
        $db->method('beginTransaction')->willReturnCallback(fn () => $this->db->beginTransaction());
        $db->method('rollBack')->willReturnCallback(fn () => $this->db->rollBack());
        $db->method('commit')->willReturnCallback($commit);
        return $db;
    }

    private function writerOn(IDBConnection $db): PolicyTransaction {
        return new PolicyTransaction($db, new PolicyStateMapper($db), $this->events($db),
            $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), $this->processor());
    }

    public function testCacheClearFailureAbandonsTheWholeWriterContext(): void {
        $apps = $this->createMock(IAppConfig::class);
        $apps->method('clearCache')->willThrowException(new \RuntimeException('cache unavailable'));
        $writer = $this->writer(apps: $apps);
        foreach ([1, 2] as $id) {
            try {
                $writer->runInternal([], fn () => null, $this->write($id));
                $this->fail('Abandoned context accepted');
            } catch (PolicyUnavailable) {
            }
        }
        $this->assertSame([], $this->database->rows('unit_policy'));
    }

    /** clearCache fails on the given call: 1 before admission, 2 under the mutex, 3 after the attempt. */
    private function appsFailingCacheClearOnCall(int $failing, \Throwable $error): IAppConfig {
        $calls = 0;
        $apps = $this->createMock(IAppConfig::class);
        $apps->method('clearCache')->willReturnCallback(function () use (&$calls, $failing, $error): void {
            if (++$calls === $failing) {
                throw $error;
            }
        });
        return $apps;
    }

    public function testCacheClearFailureAfterCommitReportsAnUncertainOutcomeAndKeepsTheCause(): void {
        $cause = new \RuntimeException('cache unavailable');
        $writer = $this->writer(apps: $this->appsFailingCacheClearOnCall(3, $cause));
        try {
            $writer->runInternal([], fn () => null, $this->write(1));
            $this->fail('Post-commit cache failure hidden');
        } catch (PolicyUnavailable $e) {
            $this->assertTrue($e->uncertain);
            $this->assertSame($cause, $e->getPrevious());
        }
        // The write is durable: the admin must reload, not retry a write that already happened.
        $this->assertCount(1, $this->database->rows('unit_policy'));
        $this->assertSame('1', $this->state->state()['revision']);
        $this->expectException(PolicyUnavailable::class);
        $writer->runInternal([], fn () => $this->fail('Abandoned writer reused'), $this->write(2));
    }

    public function testCacheClearFailureAfterAFailedAttemptChainsTheAttemptsOwnError(): void {
        $failure = new \LogicException('command failed');
        $writer = $this->writer(apps: $this->appsFailingCacheClearOnCall(3, new \RuntimeException('cache unavailable')));
        try {
            $writer->runInternal([], fn () => null, function () use ($failure): bool {
                $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
                throw $failure;
            });
            $this->fail('Failed attempt accepted');
        } catch (PolicyUnavailable $e) {
            $this->assertFalse($e->uncertain);
            $this->assertSame($failure, $e->getPrevious());
        }
        $this->assertSame([], $this->database->rows('unit_policy'));
        $this->assertSame('0', $this->state->state()['revision']);
    }

    public function testCacheClearFailureAfterAnUnknownCommitStaysUncertain(): void {
        $db = $this->boundaryConnection(function (): void {
            $this->db->commit();
            throw new \RuntimeException('connection lost');
        });
        $writer = new PolicyTransaction($db, new PolicyStateMapper($db), $this->events($db),
            $this->createMock(IUserConfig::class), $this->appsFailingCacheClearOnCall(3, new \RuntimeException('cache unavailable')), $this->processor());
        try {
            $writer->runInternal([], fn () => null, $this->write(1));
            $this->fail('Unknown commit accepted');
        } catch (PolicyUnavailable $e) {
            $this->assertTrue($e->uncertain);
            $this->assertInstanceOf(PolicyUnavailable::class, $e->getPrevious());
            $this->assertTrue($e->getPrevious()->uncertain);
        }
    }

    public function testOutboxEventsCommitWithTheChangeAndKeepTheirIdsAcrossRetries(): void {
        $attempts = 0;
        $ids = [];
        $result = $this->writer->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use (&$attempts, &$ids): bool {
            $outbox = $attempt->outbox();
            $ids[] = [$outbox->add(PolicyEventsMapper::KIND_AUDIT, ['n' => 1]), $outbox->add(PolicyEventsMapper::KIND_CLEANUP, ['uid' => 'alice'])];
            $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
            if (++$attempts < 2) {
                throw new SnapshotInvalidated();
            }
            return true;
        });
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame([$result['commandId'] . '-1', $result['commandId'] . '-2'], $ids[0]);
        $rows = $this->database->rows('mcp_policy_events');
        $this->assertSame($ids[0], array_column($rows, 'id'));
        $this->assertSame(['audit', 'cleanup'], array_column($rows, 'kind'));
        $this->assertSame(['pending', 'pending'], array_column($rows, 'status'));
        $this->assertSame(['n' => 1], $this->events()->find($ids[0][0])['payload']);
    }

    public function testFailureAfterTheOutboxWriteRollsBackTheEventsWithTheChange(): void {
        $events = new class($this->db, $this->createMock(ITimeFactory::class)) extends PolicyEventsMapper {
            public function append(string $id, string $kind, array $payload): void {
                parent::append($id, $kind, $payload);
                throw new \RuntimeException('injected after outbox');
            }
        };
        $writer = new PolicyTransaction($this->db, $this->state, $events,
            $this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), $this->processor());
        try {
            $writer->runInternal([], fn () => null, function ($s, PolicyAttempt $attempt): bool {
                $outbox = $attempt->outbox();
                $outbox->add(PolicyEventsMapper::KIND_AUDIT, []);
                $this->database->dbal->insert('unit_policy', ['id' => 1, 'value' => 'new']);
                return true;
            });
            $this->fail('Injected failure swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected after outbox', $e->getMessage());
        }
        $this->assertSame([], $this->database->rows('mcp_policy_events'));
        $this->assertSame([], $this->database->rows('unit_policy'));
        $this->assertSame(['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0'], $this->state->state());
    }

    public function testAnUnknownOutboxKindFailsTheCommandBeforeAnyWrite(): void {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->writer->runInternal([], fn () => null, function ($s, PolicyAttempt $attempt): bool {
                $outbox = $attempt->outbox();
                $outbox->add('intent', []);
                return true;
            });
        } finally {
            $this->assertSame([], $this->database->rows('mcp_policy_events'));
            $this->assertSame('0', $this->state->state()['revision']);
        }
    }

    public function testAttemptCannotBeReusedAfterCommitAndCutAloneAdvancesRevision(): void {
        $saved = null;
        $out = $this->writer->runInternal([], fn () => null, function ($snapshot, PolicyAttempt $attempt) use (&$saved): bool {
            $saved = $attempt;
            $this->assertSame('0', $attempt->state()['revision']);
            $this->assertSame($attempt->commandId() . '-1', $attempt->outbox()->add(PolicyEventsMapper::KIND_RECEIPT, []));
            $attempt->markCut();
            return false;
        });
        $this->assertTrue($out['changed']);
        $this->assertSame('1', $out['revision']);
        $this->expectException(\LogicException::class);
        $saved->markCut();
    }

    public function testLockOnlyWorkReturnsItsValueWithoutAdvancingRevision(): void {
        $this->assertSame('deleted', $this->writer->underMutex(function (): string {
            $this->assertTrue($this->db->inTransaction());
            return 'deleted';
        }));
        $this->assertSame('0', $this->state->state()['revision']);
    }

    public function testFailedRollbackAbandonsTheContextAndPreservesItsCause(): void {
        $active = false;
        $cause = new \RuntimeException('rollback connection lost');
        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturnCallback(function () use (&$active): bool { return $active; });
        $db->method('beginTransaction')->willReturnCallback(function () use (&$active): void { $active = true; });
        $db->method('rollBack')->willThrowException($cause);
        $state = $this->createMock(PolicyStateMapper::class);
        $state->method('state')->willReturn(['revision' => '0', 'auth_epoch' => '0', 'lock_seq' => '0']);
        $writer = new PolicyTransaction($db, $state, $this->events(), $this->createMock(IUserConfig::class),
            $this->createMock(IAppConfig::class), $this->processor());
        try {
            $writer->runInternal([], fn () => null, static fn () => throw new SnapshotInvalidated());
            $this->fail('Failed rollback was hidden');
        } catch (PolicyUnavailable $e) {
            $this->assertSame($cause, $e->getPrevious());
            $this->assertFalse($e->uncertain);
        }
        $this->expectException(PolicyUnavailable::class);
        $writer->assertUsable();
    }

}
