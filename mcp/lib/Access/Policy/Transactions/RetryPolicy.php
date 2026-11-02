<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use OCP\DB\Exception as DatabaseException;
use Throwable;

/** The unchanged bounded database/driver retry classification; never retries unclassified failures. */
final class RetryPolicy {
	/**
	 * Only explicit deadlock/timeout classifications are safe to replay, including at begin/commit. Core leaves the
	 * reason of some of them unset (PostgreSQL lock_timeout, SQLSTATE 55P03, has no Doctrine class), so a database
	 * exception without one is classified by the driver error it wraps.
	 * @param Throwable $error Failure raised at an owned transaction boundary.
	 * @return bool Whether replay is explicitly safe.
	 */
	public static function retryable(Throwable $error): bool {
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

	/**
	 * Follows only database exception chains, at most five links.
	 *
	 * @param Throwable $error Database wrapper.
	 * @return bool Whether a bounded driver cause permits replay.
	 */
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
