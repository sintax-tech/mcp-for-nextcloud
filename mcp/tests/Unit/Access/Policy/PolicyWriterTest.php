<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\Policy\SnapshotInvalidated;
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

final class PolicyWriterTest extends TestCase {
	private SqliteDatabase $database;
	private IDBConnection $db;
	private PolicyStateMapper $state;
	private PolicyWriter $writer;

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

	private function writer(?PolicyStateMapper $state = null, ?IUserConfig $users = null, ?IAppConfig $apps = null): PolicyWriter {
		return new PolicyWriter($this->db, $state ?? $this->state, $this->events(),
			(new InMemoryConfig())->mock($this), $users ?? $this->createMock(IUserConfig::class),
			$apps ?? $this->createMock(IAppConfig::class), $this->createMock(IUserManager::class), ...$this->tail($this->db, new InMemoryOAuthStore(), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class)));
	}

	/**
	 * The constructor tail after the user manager: clock, generations, causes, the event delivery over $oauth and
	 * the group storage on $db.
	 *
	 * @return list<object>
	 */
	private function tail(IDBConnection $db, \OCA\Mcp\OAuth\OAuthStore $oauth, ITimeFactory $time): array {
		return [$time, new \OCA\Mcp\Db\AuthUsersMapper($db), new \OCA\Mcp\Db\RecheckMapper($db),
			new \OCA\Mcp\Access\Policy\PolicyEventProcessor($this->events($db), $oauth, new \Psr\Log\NullLogger(), $time),
			new \OCA\Mcp\Db\GroupGrantsMapper($db), new \OCA\Mcp\Db\GroupLifeMapper($db), $oauth];
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

	public function testSettersPreserveRawPresenceAndSkipOnlyIdenticalRawValues(): void {
		$data = new InMemoryConfig();
		$config = $data->mock($this);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$apps = $this->createMock(IAppConfig::class);
		$apps->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
		$writer = new PolicyWriter($this->db, $this->state, $this->events(), $config, $this->createMock(IUserConfig::class), $apps, $users, ...$this->tail($this->db, new InMemoryOAuthStore(), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class)));
		// Absence/default false -> explicit deny is a real raw change.
		$writer->setGrant('alice', 'notes', 'edit', false);
		$this->assertSame('0', $data->user['alice']['mcp']['grant_notes_edit']);
		$this->assertSame('1', $this->state->state()['revision']);
		$writer->setGrant('alice', 'notes', 'edit', false);
		$this->assertSame('1', $this->state->state()['revision']);
		$writer->resetOverride('alice', 'notes', 'edit');
		$this->assertArrayNotHasKey('grant_notes_edit', $data->user['alice']['mcp']);
		$this->assertSame('2', $this->state->state()['revision']);
		$writer->resetOverride('alice', 'notes', 'edit');
		$this->assertSame('2', $this->state->state()['revision']);
		$writer->setGlobalEnabled(true);
		$writer->setGlobalEnabled(true);
		$writer->setEligible('alice', true);
		$writer->setConnected('alice', true);
		$this->assertSame('5', $this->state->state()['revision']);
		$this->assertSame('1', $data->app['mcp']['service_enabled']);
		$this->assertSame('1', $data->user['alice']['mcp']['eligible']);
		$this->assertSame('1', $data->user['alice']['mcp']['connected']);
	}

	public function testUnknownUserAndInvalidCatalogFailBeforeAnyTransactionWrite(): void {
		$writer = $this->writer();
		foreach ([fn () => $writer->setEligible('missing', true), fn () => $writer->setGrant('alice', 'files', 'destroy', true)] as $command) {
			try {
				$command();
				$this->fail('Invalid input accepted');
			} catch (\InvalidArgumentException) {
			}
			$this->assertSame('0', $this->state->state()['lock_seq']);
			$this->assertFalse($this->db->inTransaction());
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

	private function writerOn(IDBConnection $db): PolicyWriter {
		return new PolicyWriter($db, new PolicyStateMapper($db), $this->events($db), (new InMemoryConfig())->mock($this),
			$this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class),
			$this->createMock(IUserManager::class), ...$this->tail($this->db, new InMemoryOAuthStore(), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class)));
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
		$writer = new PolicyWriter($db, new PolicyStateMapper($db), $this->events($db), (new InMemoryConfig())->mock($this),
			$this->createMock(IUserConfig::class), $this->appsFailingCacheClearOnCall(3, new \RuntimeException('cache unavailable')),
			$this->createMock(IUserManager::class), ...$this->tail($this->db, new InMemoryOAuthStore(), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class)));
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
		$result = $this->writer->runInternal([], fn () => null, function ($snapshot, $state, $commandId, PolicyOutbox $outbox) use (&$attempts, &$ids): bool {
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
		$writer = new PolicyWriter($this->db, $this->state, $events, (new InMemoryConfig())->mock($this),
			$this->createMock(IUserConfig::class), $this->createMock(IAppConfig::class), $this->createMock(IUserManager::class), ...$this->tail($this->db, new InMemoryOAuthStore(), $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class)));
		try {
			$writer->runInternal([], fn () => null, function ($s, $c, $id, PolicyOutbox $outbox): bool {
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
			$this->writer->runInternal([], fn () => null, function ($s, $c, $id, PolicyOutbox $outbox): bool {
				$outbox->add('intent', []);
				return true;
			});
		} finally {
			$this->assertSame([], $this->database->rows('mcp_policy_events'));
			$this->assertSame('0', $this->state->state()['revision']);
		}
	}

	/** A writer over the SQLite state and events, a config double and a clock at $now. */
	private function commandWriter(InMemoryConfig $data, int &$now, ?\OCA\Mcp\Db\AuthUsersMapper $authUsers = null): PolicyWriter {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'ghost' ? null : $this->createMock(IUser::class));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function () use (&$now): int {
			return $now;
		});
		$config = $data->mock($this);
		$apps = $this->createMock(IAppConfig::class);
		$apps->method('hasKey')->willReturnCallback(fn ($app, $key): bool => array_key_exists($key, $data->app[$app] ?? []));
		return new PolicyWriter($this->db, $this->state, new PolicyEventsMapper($this->db, $time), $config,
			$this->createMock(IUserConfig::class), $apps, $users, $time, $authUsers ?? new \OCA\Mcp\Db\AuthUsersMapper($this->db),
			new \OCA\Mcp\Db\RecheckMapper($this->db), new PolicyEventProcessor($this->events(), new InMemoryOAuthStore(), new \Psr\Log\NullLogger(), $time),
			new \OCA\Mcp\Db\GroupGrantsMapper($this->db), new \OCA\Mcp\Db\GroupLifeMapper($this->db), new \OCA\Mcp\OAuth\OAuthStore($this->db));
	}

	/** @return list<array{id:string, kind:string, payload:array}> the stored events, decoded */
	private function storedEvents(): array {
		return array_map(static fn (array $row): array => ['id' => $row['id'], 'kind' => $row['kind'], 'payload' => json_decode($row['payload'], true)],
			// Ids start with a random command id: order by time, then by the sequence within one command.
			$this->database->dbal->fetchAllAssociative('SELECT * FROM mcp_policy_events ORDER BY created_at, id'));
	}

	public function testACommandCommitsItsReceiptAndAuditWithTheRevisionAndAFailureLeavesNeither(): void {
		$data = new InMemoryConfig();
		$now = 1760000000;
		// The last cell revokes bob: fail its cut, after every config write of the command.
		$authUsers = $this->createMock(\OCA\Mcp\Db\AuthUsersMapper::class);
		$authUsers->method('generations')->willReturn(['issue_seq' => '1', 'revoked_below' => '0']);
		$authUsers->method('cut')->willThrowException(new \RuntimeException('injected in the last cell'));
		try {
			$this->commandWriter($data, $now, $authUsers)->applyCells('0', 'root', 'grants.commands', [
				['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1'],
				['uid' => 'bob', 'key' => 'eligible', 'value' => '0'],
			]);
			$this->fail('Injected failure swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('injected in the last cell', $e->getMessage());
		}
		// The database side rolled back: no revision, no receipt, no audit (the config double is not transactional).
		$this->assertSame(['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0'], $this->state->state());
		$this->assertSame([], $this->storedEvents());

		$data = new InMemoryConfig();
		$result = $this->commandWriter($data, $now)->applyCells('0', 'root', 'grants.commands', [
			['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1'],
			['uid' => 'bob', 'key' => 'eligible', 'value' => '0'],
		]);
		$this->assertSame('1', $result['revision']);
		$events = $this->storedEvents();
		// Revoking bob writes the cleanup of his cut in the same transaction, then the receipt and the audit.
		$this->assertSame([PolicyEventsMapper::KIND_CLEANUP, PolicyEventsMapper::KIND_RECEIPT, PolicyEventsMapper::KIND_AUDIT], array_column($events, 'kind'));
		$this->assertSame(['v' => 1, 'scope' => 'user', 'uid' => 'bob', 'cut' => '1', 'epoch' => null], $events[0]['payload']);
		$events = array_slice($events, 1);
		$this->assertSame($result['receiptId'], $events[0]['id']);
		$this->assertSame(['0', '1'], [$events[0]['payload']['fromRevision'], $events[0]['payload']['revision']]);
		$this->assertSame($result['receiptId'], $events[1]['payload']['receiptId']);
	}

	public function testUndoReadsTheStoredReceiptRestoresAbsenceAndExpiresAfterItsTtl(): void {
		$data = new InMemoryConfig();
		$data->user['bob']['mcp']['grant_deck_move'] = '0';
		$now = 1760000000;
		$writer = $this->commandWriter($data, $now);
		$saved = $writer->applyCells('0', 'root', 'grants.bulk', [
			['uid' => 'alice', 'key' => 'grant_deck_move', 'value' => '1'],
			['uid' => 'bob', 'key' => 'grant_deck_move', 'value' => '1'],
		]);
		$now += PolicyWriter::RECEIPT_TTL - 1; // the last second it is still valid
		$this->assertSame(['revision' => '2', 'changed' => 2], $writer->restoreReceipt($saved['receiptId'], '1', 'root'));
		$this->assertArrayNotHasKey('grant_deck_move', $data->user['alice']['mcp']);
		$this->assertSame('0', $data->user['bob']['mcp']['grant_deck_move']);
		$undo = $this->storedEvents()[2]['payload'];
		$this->assertSame(['grants.undo', $saved['receiptId'], null], [$undo['action'], $undo['undoOf'], $undo['receiptId']]);

		$again = $writer->applyCells('2', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'eligible', 'value' => '1']]);
		$now += PolicyWriter::RECEIPT_TTL;
		$this->expectException(\OCA\Mcp\Access\Policy\ReceiptExpired::class);
		$writer->restoreReceipt($again['receiptId'], '3', 'root');
	}

	public function testACorruptStoredReceiptIsAStorageFailureNeverAPartialUndo(): void {
		$data = new InMemoryConfig();
		$now = 1760000000;
		$writer = $this->commandWriter($data, $now);
		$saved = $writer->applyCells('0', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1']]);
		$this->database->dbal->update('mcp_policy_events', ['payload' => json_encode(['v' => 1, 'actor' => 'root'])], ['id' => $saved['receiptId']]);
		try {
			$writer->restoreReceipt($saved['receiptId'], '1', 'root');
			$this->fail('Corrupt receipt accepted');
		} catch (PolicyUnavailable) {
		}
		$this->assertSame('1', $data->user['alice']['mcp']['grant_notes_edit']);
		$this->assertSame('1', $this->state->state()['revision']);
	}

	public function testAnAuditEventIdIsNeverAcceptedAsAReceipt(): void {
		$data = new InMemoryConfig();
		$now = 1760000000;
		$writer = $this->commandWriter($data, $now);
		$saved = $writer->applyCells('0', 'root', 'grants.update', [['uid' => 'alice', 'key' => 'grant_notes_edit', 'value' => '1']]);
		$audit = $this->storedEvents()[1]['id'];
		$this->assertNotSame($saved['receiptId'], $audit);
		$this->expectException(\OCA\Mcp\Access\Policy\ReceiptNotFound::class);
		$writer->restoreReceipt($audit, '1', 'root');
	}
}
