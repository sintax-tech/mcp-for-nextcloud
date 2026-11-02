<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use Closure;
use OCA\Mcp\Access\Policy\{PolicyConflict, PolicyEventProcessor, PolicyOutbox, PolicyUnavailable, SnapshotInvalidated};
use OCA\Mcp\Db\{PolicyEventsMapper, PolicyStateMapper};
use OCA\Mcp\Platform\Numbers\DecimalCounter;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\{IAppConfig, IDBConnection};
use Throwable;

/**
 * Owns the only top-level transaction: external admission, mutex, local statements, revision CAS, outbox and commit.
 * Every attempt shares one connection/context with the facade; physical cleanup starts only after settlement.
 */
final class PolicyTransaction {
	/**
	 * @param IDBConnection $db Official singleton connection used by every participant.
	 * @param PolicyStateMapper $state Global mutex, revision and epoch store.
	 * @param PolicyEventsMapper $events Transactional outbox storage.
	 * @param IUserConfig $userConfig Official user cache invalidation.
	 * @param IAppConfig $appConfig Official app cache invalidation.
	 * @param PolicyEventProcessor $processor Delivery after a durable commit.
	 * @param PolicyContext $context Exactly this writer's shared mutable context.
	 */
	public function __construct(
		private IDBConnection $db,
		private PolicyStateMapper $state,
		private PolicyEventsMapper $events,
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private PolicyEventProcessor $processor,
		private PolicyContext $context,
	) {}

	/** Read admission shares the abandoned-context gate. @throws PolicyUnavailable When unusable. */
	public function assertUsable(): void {
		$this->context->assertUsable();
	}

	/**
	 * Executes one logical command with at most three attempts and a fresh outbox per attempt.
	 * @param list<string> $uids Users whose official caches may be touched.
	 * @param Closure(array{revision:string, lock_seq:string, auth_epoch:string}, string):mixed $prepare External admission.
	 * @param Closure(mixed, array{revision:string, lock_seq:string, auth_epoch:string}, string, PolicyOutbox):bool $apply Local writes.
	 * @param string|null $expected Canonical required revision, or null for an internal command.
	 * @return array{revision:string, changed:bool, commandId:string, cleanupPending:bool}
	 * @throws PolicyConflict Stale required revision or exhausted snapshot retries.
	 * @throws PolicyUnavailable Storage/cache failure, abandoned context or uncertain commit.
	 * @throws Throwable The unchanged application exception when rollback succeeded.
	 */
	public function run(array $uids, Closure $prepare, Closure $apply, ?string $expected): array {
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
				$this->context->attempt = new PolicyAttempt($outbox, $current);
				$changed = $apply($snapshot, $current, $commandId, $outbox);
				if (!is_bool($changed)) {
					throw new \LogicException('Policy command must report whether raw policy changed');
				}
				// A cut or epoch change is a policy write even when no cell changed (coordinator clarification 1).
				$changed = $changed || $this->context->attempt->cut;
				$this->context->attempt = null;
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
				$this->context->attempt = null;
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

	/**
	 * Rolls back an attempt the writer owns and classifies its failure.
	 *
	 * @param Throwable $e Attempt failure.
	 * @param bool $owned Whether this engine began the attempt.
	 * @param bool $committing Whether commit started.
	 * @param int $attempt Zero-based attempt number.
	 * @param string|null $expected Required administrative revision.
	 * @return Throwable|null the exception to surface, or null when the whole attempt may be replayed
	 */
	private function failure(Throwable $e, bool $owned, bool $committing, int $attempt, ?string $expected): ?Throwable {
		$retry = $e instanceof SnapshotInvalidated || ($owned && RetryPolicy::retryable($e));
		if ($owned) {
			try {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
			} catch (Throwable $rollback) {
				$this->context->usable = false;
				return new PolicyUnavailable('Policy rollback failed', $committing, $rollback);
			}
		}
		if ($committing && !$retry) {
			$this->context->usable = false;
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

	/**
	 * The cache clear that closes every attempt. If it fails the context is abandoned, but the outcome it reports
	 * stays honest: after a commit the write is durable, so the caller is told the state is uncertain (reload), never
	 * merely unavailable; and an attempt's own failure stays chained instead of being replaced by the cache error.
	 *
	 * @param list<string> $uids Touched cache owners.
	 * @param bool $committed Whether the write is durable.
	 * @param Throwable|null $failure Attempt failure to preserve in the chain.
	 * @throws PolicyUnavailable when the caches cannot be cleared
	 */
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

	/** Invalidates official caches. @param list<string> $uids Cache owners. @throws PolicyUnavailable Abandons context on failure. */
	private function clearCaches(array $uids): void {
		try {
			foreach ($uids as $uid) {
				$this->userConfig->clearCache($uid, false);
			}
			$this->appConfig->clearCache(false);
		} catch (Throwable $e) {
			$this->context->usable = false;
			throw new PolicyUnavailable('Cannot clear policy caches; context abandoned', previous: $e);
		}
	}
}
