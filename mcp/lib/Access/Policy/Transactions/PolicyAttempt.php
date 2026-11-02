<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Transactions;

use OCA\Mcp\Access\Policy\PolicyOutbox;

/** Mutable state of exactly one owned attempt; never shared between writers or retries. */
final class PolicyAttempt {
	/** @var bool Whether a generation cut or epoch changed despite unchanged raw policy. */
	public bool $cut = false;

	/**
	 * @param PolicyOutbox $outbox Fresh events for this attempt, derived from its logical command ID.
	 * @param array{revision:string, lock_seq:string, auth_epoch:string} $state Row read under the mutex.
	 */
	public function __construct(public PolicyOutbox $outbox, public array $state) {}
}
