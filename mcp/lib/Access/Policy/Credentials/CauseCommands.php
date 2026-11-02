<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\SnapshotInvalidated;
use OCA\Mcp\Access\Policy\Transactions\PolicyContext;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Db\RecheckMapper;
use Throwable;

/** Classifies and consumes pending causes with their exact generations. */
final class CauseCommands implements CauseCommandsContract {
	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param RecheckMapper $rechecks Pending causes on the official connection.
	 * @param LocalCredentialEffects $effects Cuts before consuming causes.
	 * @param PolicyContext $context The same mutable context as the transaction.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private RecheckMapper $rechecks,
		private LocalCredentialEffects $effects,
		private PolicyContext $context,
	) {}

	/** {@inheritDoc} */
	public function processCauses(string $uid, Closure $classify, ?Closure $select = null): int {
		if (!PolicyEventPayload::validUid($uid)) {
			throw new \InvalidArgumentException('Invalid user');
		}
		$choose = static fn (array $causes): array => $select === null ? $causes : array_values(array_filter($causes, $select));
		try {
			if ($choose($this->rechecks->causes($uid)) === []) {
				return 0;
			}
		} catch (PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new PolicyUnavailable('Cannot read recheck causes', previous: $e);
		}
		$consumed = 0;
		$this->execution->runInternal([$uid], function () use ($uid, $classify, $choose): array {
			$causes = $this->rechecks->causes($uid);
			$chosen = $choose($causes);
			return ['causes' => $causes, 'classified' => self::classified($chosen, $chosen === [] ? [] : $classify($uid, $chosen))];
		}, function (array $prepared) use ($uid, &$consumed): bool {
			if ($this->rechecks->causes($uid) !== $prepared['causes']) {
				throw new SnapshotInvalidated();
			}
			$consumed = $this->consumeLocal($uid, $prepared['classified']);
			return $consumed > 0;
		});
		return $consumed;
	}

	/**
	 * Pairs each selected cause with its classification; a classification that misses a cause is a programming error.
	 *
	 * @param list<array{causeKey:string, gen:string}> $causes
	 * @param array<string, bool> $classification cause_key => cut
	 * @return list<array{causeKey:string, gen:string, cut:bool}>
	 * @throws \LogicException When any selected cause lacks a boolean classification.
	 */
	public static function classified(array $causes, array $classification): array {
		$classified = [];
		foreach ($causes as $cause) {
			if (!is_bool($classification[$cause['causeKey']] ?? null)) {
				throw new \LogicException('Every recheck cause must be classified');
			}
			$classified[] = ['causeKey' => $cause['causeKey'], 'gen' => $cause['gen'], 'cut' => $classification[$cause['causeKey']]];
		}
		return $classified;
	}

	/**
	 * Step 4 of the cause protocol, under the mutex: the cut demanded by any classified cause (at issue_seq read now),
	 * then the delete of each cause by user, cause and gen. A consumed cause is a policy write (coordinator
	 * clarification 1), so the attempt advances the revision.
	 *
	 * @param string $uid Owner of the selected causes.
	 * @param list<array{causeKey:string, gen:string, cut:bool}> $classified
	 * @return int causes deleted
	 */
	public function consumeLocal(string $uid, array $classified): int {
		if ($classified === []) {
			return 0;
		}
		if (in_array(true, array_column($classified, 'cut'), true)) {
			$this->effects->revokeUserLocal($uid);
		}
		$consumed = 0;
		foreach ($classified as $cause) {
			if ($this->rechecks->consume($uid, $cause['causeKey'], $cause['gen'])) {
				++$consumed;
			}
		}
		if ($consumed > 0) {
			$this->context->attempt->cut = true;
		}
		return $consumed;
	}
}
