<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;

/**
 * Receipt of an attempted structure operation, built only from a fresh authorized readback.
 *
 * Applied needs a valid native response, the complete predicted effect, the actor's renewed 15-minute lock, lastUpdated inside
 * the attempt window, the predicted confirmation selection and an unchanged state/owner. Otherwise partial when something
 * changed, uncertain when nothing was observed. Success is never inferred from the native status alone.
 */
final class StructureReceipt {
	/** Static receipt builder only: prevents instantiation. */
	private function __construct() {
	}

	/**
	 * @param StructureOperation $op operation
	 * @param EditContext $c re-admitted native graph
	 * @param array<string,mixed> $r validated request
	 * @param array<string,mixed> $before snapshot the plan was built on
	 * @param array<string,mixed> $e predicted effect
	 * @param array<string,mixed> $after fresh snapshot after the attempt
	 * @param mixed $response native response
	 * @param string $uid actor
	 * @param int $t0 time right before the native call
	 * @param int $t1 time right after it
	 * @return array<string,mixed> receipt
	 */
	public static function observed(StructureOperation $op, EditContext $c, array $r, array $before, array $e, array $after,
		mixed $response, string $uid, int $t0, int $t1): array {
		$valid = $op->responseValid($response, $r);
		$o = $op->observe($c, $r, $e, $after, $response);
		$h = $after['header'];
		$lock = $h['lockedBy'] === $uid && is_int($h['lockedUntil']) && $t1 >= $t0 && $h['lockedUntil'] >= $t0 + 900 && $h['lockedUntil'] <= $t1 + 900;
		$updated = is_int($h['lastUpdated']) && $t1 >= $t0 && $h['lastUpdated'] >= $t0 && $h['lastUpdated'] <= $t1;
		$selection = $h['selector'] === $e['selector'] && $h['confirmationEnabled'] === $before['header']['confirmationEnabled'];
		$complete = $valid && $o['complete'] && $lock && $updated && $selection
			&& $h['state'] === $before['header']['state'] && $h['owner'] === $before['header']['owner'];
		$receipt = ['status' => $complete ? 'applied' : ($o['changed'] ? 'partial' : 'uncertain'), 'nativeCallAttempted' => true,
			'target' => $e['target'] + ['formId' => $r['form_id']], 'observed' => $o['observed'], 'matchesRequestedEffect' => $o['complete'],
			'nativeResponseValid' => $valid,
			'lock' => ['nativeDurationSeconds' => 900, 'actorLockObserved' => $lock, 'lastUpdated' => $h['lastUpdated'],
				'lastUpdatedInAttemptWindowObserved' => $updated, 'unlockAttempted' => false],
			'confirmation' => $e['confirmation'] === null ? null : ['selected_for_confirmation' => $h['selector'] !== null && $h['selector'] === ($r['question_id'] ?? null),
				'confirmation_enabled_on_form' => $h['confirmationEnabled'], 'confirmation_allowed_by_app' => $h['confirmationAllowedByApp'],
				'selection_empty_observed' => $h['selector'] === null, 'expectedSelectionStateObserved' => $selection],
			'notes' => StructureMessages::receiptNotes()];
		return $receipt + ($o['extra'] ?? []);
	}
}
