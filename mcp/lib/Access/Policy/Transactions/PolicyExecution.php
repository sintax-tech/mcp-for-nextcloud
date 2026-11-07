<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use Closure;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Platform\Numbers\DecimalCounter;

/** Administrative and internal entry points over the single policy transaction. */
final class PolicyExecution implements PolicyExecutionContract {
	/** Most users one administrative command may touch; the facade retains its public alias. */
	public const MAX_UIDS = 50;

	/** @param PolicyTransaction $transaction The execution engine sharing this writer's context. */
	public function __construct(private PolicyTransaction $transaction) {}

	/** {@inheritDoc} */
	public function assertUsable(): void {
		$this->transaction->assertUsable();
	}

	/** {@inheritDoc} */
	public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array {
		DecimalCounter::validate($expectedRevision);
		if (count(array_unique($uids)) > self::MAX_UIDS) {
			throw new \InvalidArgumentException('Too many policy users');
		}
		return $this->transaction->run($uids, $prepare, $apply, $expectedRevision);
	}

	/** {@inheritDoc} */
	public function runInternal(array $uids, Closure $prepare, Closure $apply): array {
		return $this->transaction->run($uids, $prepare, $apply, null);
	}

	/** {@inheritDoc} */
	public function underMutex(Closure $local): mixed {
		$value = null;
		$this->runInternal([], fn () => null, function () use ($local, &$value): bool {
			$value = $local();
			return false;
		});
		return $value;
	}

	/** {@inheritDoc} */
	public static function next(string $revision): string {
		try {
			return DecimalCounter::increment($revision);
		} catch (\InvalidArgumentException $e) {
			throw new PolicyUnavailable('Policy revision exhausted', previous: $e);
		}
	}
}
