<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** One writer's admission and active-attempt state, shared explicitly with its transaction. */
final class PolicyContext {
	/** @var bool False after rollback, uncertain commit or cache failure abandons this writer. */
	public bool $usable = true;
	/** @var PolicyAttempt|null The active local phase, cleared on success and failure. */
	public ?PolicyAttempt $attempt = null;

	/** Rejects every subsequent admission after abandonment. @throws PolicyUnavailable When unusable. */
	public function assertUsable(): void {
		if (!$this->usable) {
			throw new PolicyUnavailable('Policy context was abandoned');
		}
	}

	/**
	 * Returns the same local-phase snapshot exposed by the historical writer.
	 * @return array{outbox:PolicyOutbox, state:array{revision:string, lock_seq:string, auth_epoch:string}, cut:bool}
	 * @throws \LogicException Outside a local command attempt.
	 */
	public function localPhase(): array {
		if ($this->attempt === null) {
			throw new \LogicException('Revocations run only in the local phase of a policy command');
		}
		return ['outbox' => $this->attempt->outbox, 'state' => $this->attempt->state, 'cut' => $this->attempt->cut];
	}
}
