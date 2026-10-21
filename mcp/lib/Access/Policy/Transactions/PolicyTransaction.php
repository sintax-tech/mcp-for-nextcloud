<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use Closure;
use Throwable;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Events\PolicyOutbox;
use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IDBConnection;

/** Owns the single policy mutex, transaction, retry and cache-invalidation boundary. */
class PolicyTransaction {
    private bool $usable = true;

    public function __construct(
        private IDBConnection $db,
        private PolicyStateMapper $state,
        private PolicyEventsMapper $events,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private PolicyEventProcessor $processor,
    ) {}

    public function assertUsable(): void {
        if (!$this->usable) {
            throw new PolicyUnavailable('Policy context was abandoned');
        }
    }

    /**
     * @param list<string> $uids
     * @param Closure(array,string):mixed $prepare outside the transaction
     * @param Closure(mixed,PolicyAttempt):bool $apply local statements under the mutex
     * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
     */
    public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array {
        DecimalCounter::validate($expectedRevision);
        if (count(array_unique($uids)) > PolicyLimits::MAX_UIDS) {
            throw new \InvalidArgumentException('Too many policy users');
        }
        return $this->run($uids, $prepare, $apply, $expectedRevision);
    }

    /**
     * @param list<string> $uids
     * @param Closure(array,string):mixed $prepare outside the transaction
     * @param Closure(mixed,PolicyAttempt):bool $apply local statements under the mutex
     * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
     */
    public function runInternal(array $uids, Closure $prepare, Closure $apply): array {
        return $this->run($uids, $prepare, $apply, null);
    }

    public function underMutex(Closure $local): mixed {
        $value = null;
        $this->runInternal([], fn () => null, function () use ($local, &$value): bool {
            $value = $local();
            return false;
        });
        return $value;
    }

    private function run(array $uids, Closure $prepare, Closure $apply, ?string $expected): array {
        $this->assertUsable();
        if ($this->db->inTransaction()) {
            throw new PolicyUnavailable('Policy writer requires a top-level transaction');
        }
        $uids = array_values(array_unique($uids));
        $commandId = bin2hex(random_bytes(16));
        for ($attempt = 0; ; ++$attempt) {
            $owned = false;
            $committing = false;
            $committed = false;
            $result = null;
            $failure = null;
            try {
                $this->clearCaches($uids);
                $observed = $this->state->state();
                if ($expected !== null && $expected !== $observed['revision']) {
                    throw new PolicyConflict();
                }
                $snapshot = $prepare($observed, $commandId);
                if ($this->db->inTransaction()) {
                    throw new PolicyUnavailable('External admission opened a transaction');
                }
                // Some drivers may throw after beginning a transaction: the catch also checks inTransaction().
                $owned = true;
                $this->db->beginTransaction();
                $this->state->acquire();
                $current = $this->state->state();
                if ($current['revision'] !== $observed['revision'] || $current['auth_epoch'] !== $observed['auth_epoch']) {
                    throw new SnapshotInvalidated();
                }
                // Local reads must not reuse config populated by remote admission.
                $this->clearCaches($uids);
                // A fresh outbox per attempt: a rolled-back attempt leaves no event behind, and ids repeat on retry.
                $outbox = new PolicyOutbox($commandId);
                $local = new PolicyAttempt($current, $commandId, $outbox);
                try {
                    $changed = $apply($snapshot, $local);
                    $cut = $local->hasCut();
                } finally {
                    $local->close();
                }
                if (!is_bool($changed)) {
                    throw new \LogicException('Policy command must report whether raw policy changed');
                }
                // A cut or epoch change is a policy write even when no cell changed (coordinator clarification 1).
                $changed = $changed || $cut;
                if ($changed) {
                    try {
                        DecimalCounter::validate($current['revision']);
                    } catch (\InvalidArgumentException $e) {
                        throw new PolicyUnavailable('Policy revision exhausted', previous: $e);
                    }
                    $this->state->compareAndSet($current['revision']);
                }
                foreach ($outbox->events() as $event) {
                    $this->events->append($event['id'], $event['kind'], $event['payload']);
                }
                $revision = $this->state->state()['revision'];
                $committing = true;
                $this->db->commit();
                $committed = true;
                $owned = false;
                $result = ['revision' => $revision, 'changed' => $changed, 'commandId' => $commandId];
            } catch (Throwable $e) {
                $failure = $this->failure($e, $owned, $committing, $attempt, $expected);
            }
            $this->settle($uids, $committed, $failure);
            if ($result !== null) {
                // After the commit: physical cleanup and audit delivery, which never turn the success into an error.
                $result['cleanupPending'] = !$this->processor->afterCommit($outbox->events());
                return $result;
            }
            if ($failure !== null) {
                throw $failure;
            }
            // At most two waits of 100 ms: total app-owned waiting stays below the 1 s budget.
            usleep(random_int(25000, 100000));
        }
    }

    private function failure(Throwable $e, bool $owned, bool $committing, int $attempt, ?string $expected): ?Throwable {
        $retry = $e instanceof SnapshotInvalidated || ($owned && self::retryable($e));
        if ($owned) {
            try {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollback) {
                $this->usable = false;
                return new PolicyUnavailable('Policy rollback failed', $committing, $rollback);
            }
        }
        if ($committing && !$retry) {
            $this->usable = false;
            return new PolicyUnavailable('Policy commit outcome is uncertain; reload state', true, $e);
        }
        if (!$retry) {
            if ($e instanceof DatabaseException || $e instanceof \Doctrine\DBAL\Exception || $e instanceof \Doctrine\DBAL\Driver\Exception) {
                return new PolicyUnavailable(previous: $e);
            }
            return $e;
        }
        if ($attempt >= 2) {
            if ($e instanceof SnapshotInvalidated && $expected !== null) {
                return new PolicyConflict();
            }
            return new PolicyUnavailable('Policy storage busy; retry later', previous: $e);
        }
        return null;
    }

    private function settle(array $uids, bool $committed, ?Throwable $failure): void {
        try {
            $this->clearCaches($uids);
        } catch (PolicyUnavailable $cache) {
            if ($committed) {
                throw new PolicyUnavailable('Policy committed, but its caches could not be cleared; reload state', true, $cache->getPrevious() ?? $cache);
            }
            if ($failure !== null) {
                throw new PolicyUnavailable($cache->getMessage(), $failure instanceof PolicyUnavailable && $failure->uncertain, $failure);
            }
            throw $cache;
        }
    }

    private function clearCaches(array $uids): void {
        try {
            foreach ($uids as $uid) {
                $this->userConfig->clearCache($uid, false);
            }
            $this->appConfig->clearCache(false);
        } catch (Throwable $e) {
            $this->usable = false;
            throw new PolicyUnavailable('Cannot clear policy caches; context abandoned', previous: $e);
        }
    }

    private static function retryable(Throwable $error): bool {
        if ($error instanceof DatabaseException) {
            if (in_array($error->getReason(), [DatabaseException::REASON_DEADLOCK, DatabaseException::REASON_LOCK_WAIT_TIMEOUT], true)) {
                return true;
            }
            return self::wrapsRetryable($error);
        }
        // DBAL driver failures can escape core's wrapper at transaction boundaries.
        if ($error instanceof \Doctrine\DBAL\Exception\DeadlockException || $error instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            return true;
        }
        if ($error instanceof \Doctrine\DBAL\Driver\Exception) {
            $sqlState = $error->getSQLState();
            return in_array($sqlState, ['40001', '40P01', '55P03'], true)
                || ($sqlState === 'HY000' && in_array($error->getCode(), [5, 6, 1205, 1213], true));
        }
        return $error instanceof \Doctrine\DBAL\Exception && self::wrapsRetryable($error);
    }

    private static function wrapsRetryable(Throwable $error): bool {
        for ($depth = 0, $previous = $error->getPrevious(); $previous !== null && $depth < 5; ++$depth, $previous = $previous->getPrevious()) {
            if ($previous instanceof \Doctrine\DBAL\Exception\DeadlockException || $previous instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException) {
                return true;
            }
            if ($previous instanceof \Doctrine\DBAL\Driver\Exception) {
                return self::retryable($previous);
            }
            if (!($previous instanceof DatabaseException || $previous instanceof \Doctrine\DBAL\Exception)) {
                return false;
            }
        }
        return false;
    }

}
