<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\SnapshotInvalidated;
use OCA\Mcp\Access\Policy\Transactions\PolicyContext;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;

/** Lifecycle holds and generation cuts inside the single policy attempt. */
final class LocalCredentialEffects implements LocalCredentialEffectsContract {
	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param PolicyContext $context The same mutable context as the transaction.
	 * @param GroupLifeMapper $life Group lifecycle transitions on the official connection.
	 * @param AuthUsersMapper $authUsers User credential generations.
	 * @param PolicyStateMapper $state Global epoch on the official connection.
	 */
	public function __construct(
		private PolicyContext $context,
		private GroupLifeMapper $life,
		private AuthUsersMapper $authUsers,
		private PolicyStateMapper $state,
	) {}

	/** {@inheritDoc} */
	public function holdLifecycles(array $lifecycles): void {
		$this->localPhase();
		if ($lifecycles === []) {
			return;
		}
		$groupIds = array_values(array_unique(array_column($lifecycles, 'groupId')));
		$current = [];
		foreach ($this->life->forGroups($groupIds) as $row) {
			$current[$row['groupId']] = $row;
		}
		foreach ($groupIds as $gid) {
			$row = $current[$gid] ?? null;
			if ($row === null || $row['state'] !== GroupLifeMapper::ACTIVE
				|| !$this->life->transition($gid, $row['gen'], GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16)))) {
				throw new SnapshotInvalidated();
			}
		}
	}

	/**
	 * Cut of one user at $cut (default: issue_seq read now, under the mutex) and its cleanup event.
	 *
	 * @param string $uid Owner of the credentials.
	 * @param string|null $cut Observed issue sequence, or null to read it under the mutex.
	 * @throws \LogicException outside the local phase of an attempt
	 */
	public function revokeUserLocal(string $uid, ?string $cut = null): void {
		$local = $this->localPhase();
		$cut ??= $this->authUsers->generations($uid)['issue_seq'];
		if ($this->authUsers->cut($uid, $cut)) {
			$this->context->attempt->cut = true;
		}
		$local['outbox']->add(PolicyEventsMapper::KIND_CLEANUP, PolicyEventPayload::cleanupUser($uid, $cut));
	}

	/**
	 * Global revocation: advances the auth epoch and writes the cleanup event of the new one.
	 *
	 * @throws \LogicException outside the local phase of an attempt
	 */
	public function revokeAllLocal(): void {
		$local = $this->localPhase();
		$epoch = $this->state->advanceAuthEpoch($local['state']['auth_epoch']);
		$this->context->attempt->state['auth_epoch'] = $epoch;
		$this->context->attempt->cut = true;
		$local['outbox']->add(PolicyEventsMapper::KIND_CLEANUP, PolicyEventPayload::cleanupGlobal($epoch));
	}

	/**
	 * Delegates to the single active attempt.
	 *
	 * @return array{outbox:PolicyOutbox, state:array, cut:bool}
	 * @throws \LogicException Outside the local phase of a command.
	 */
	private function localPhase(): array {
		return $this->context->localPhase();
	}
}
