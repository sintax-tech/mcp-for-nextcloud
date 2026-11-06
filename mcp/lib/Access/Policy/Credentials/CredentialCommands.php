<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use Closure;
use OCA\Mcp\Access\Policy\{PolicyEventPayload, SnapshotInvalidated};
use OCA\Mcp\Db\{AuthUsersMapper, RecheckMapper};
use OCA\Mcp\Platform\Numbers\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;

/** Credential execution and revocation in the single policy transaction. */
final class CredentialCommands implements CredentialCommandsContract {
	/**
	 * Composes this command with the existing policy participants.
	 *
	 * @param PolicyExecution $execution The shared entry points over the only transaction.
	 * @param RecheckMapper $rechecks Pending causes on the official connection.
	 * @param AuthUsersMapper $authUsers The same user generation mapper.
	 * @param CauseCommands $causes Cause consumption in the shared attempt.
	 * @param LocalCredentialEffects $effects Cuts in the shared attempt.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private RecheckMapper $rechecks,
		private AuthUsersMapper $authUsers,
		private CauseCommands $causes,
		private LocalCredentialEffects $effects,
	) {}

	/** {@inheritDoc} */
	public function runCredential(string $uid, Closure $prepare, Closure $apply, ?Closure $classify = null): array {
		return $this->execution->runInternal([$uid], function (array $observed) use ($uid, $prepare, $classify): array {
			$causes = $this->rechecks->causes($uid);
			$before = ['generations' => $this->authUsers->generations($uid), 'causes' => $causes];
			$classified = $classify === null || $causes === [] ? [] : CauseCommands::classified($causes, $classify($uid, $causes));
			return ['before' => $before, 'classified' => $classified, 'snapshot' => $prepare($observed)];
		}, function (array $prepared, array $current) use ($uid, $apply): bool {
			if ($this->authUsers->generations($uid) !== $prepared['before']['generations']
				|| $this->rechecks->causes($uid) !== $prepared['before']['causes']) {
				throw new SnapshotInvalidated();
			}
			$consumed = $this->causes->consumeLocal($uid, $prepared['classified']) > 0;
			return $apply($prepared['snapshot'], $current, $this->authUsers->generations($uid)) || $consumed;
		});
	}

	/** {@inheritDoc} */
	public function revokeCredentials(string $uid): void {
		if (!PolicyEventPayload::validUid($uid)) {
			throw new \InvalidArgumentException('Invalid user');
		}
		$this->execution->runInternal([$uid], fn () => null, function () use ($uid): bool {
			$this->effects->revokeUserLocal($uid);
			return false;
		});
	}

	/** {@inheritDoc} */
	public function revokeObserved(string $uid, string $observed): void {
		if (!PolicyEventPayload::validUid($uid)) {
			throw new \InvalidArgumentException('Invalid user');
		}
		DecimalCounter::validate($observed, DecimalCounter::MAX);
		$this->execution->runInternal([$uid], fn () => null, function () use ($uid, $observed): bool {
			$this->effects->revokeUserLocal($uid, $observed);
			return false;
		});
	}
}
