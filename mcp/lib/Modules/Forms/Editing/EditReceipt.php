<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Modules\Forms\Presentation\EditMessages;
use OCA\Mcp\Modules\Forms\Integration\EditState;

/** Projects only authorized observed point effects, never inferring success from an HTTP status or a private old snapshot. */
final class EditReceipt {
	/** Classifies readback and separately qualifies lock, target and confirmation selection.
	 * @param array<string,mixed> $r Closed request.
	 * @param array<string,mixed> $before Authorized pre-sink snapshot.
	 * @param array<string,mixed> $effect Expected point effect.
	 * @param array<string,mixed> $after Newly authorized active readback.
	 * @param string $uid Actor.
	 * @param int $t0 Wall time immediately before native entry.
	 * @param int $t1 Wall time after native return/failure.
	 * @param bool $responseValid Native status and returned ID matched.
	 * @return array<string,mixed> Honest point receipt.
	 */
	public static function observed(array $r, array $before, array $effect, array $after, string $uid, int $t0, int $t1, bool $responseValid): array {
		$target = $r['option'] ? $after['option'] : $after['question']; $old = $r['option'] ? $before['option'] : $before['question'];
		$observed = []; $matched = []; $changed = false;
		foreach ($effect['patch'] as $key => $value) {
			$observed[$key] = $target[$key]; $matched[$key] = $target[$key] === $effect['expected'][$key];
			$changed = $changed || $target[$key] !== $old[$key];
		}
		$lock = $after['header']['lockedBy'] === $uid && is_int($after['header']['lockedUntil']) && $t1 >= $t0 && $after['header']['lockedUntil'] >= $t0 + 900 && $after['header']['lockedUntil'] <= $t1 + 900;
		$metadata = $after['header']['selector'] === $effect['selector'] && $after['header']['confirmationEnabled'] === $before['header']['confirmationEnabled'];
		$updated = is_int($after['header']['lastUpdated']) && $t1 >= $t0 && $after['header']['lastUpdated'] >= $t0 && $after['header']['lastUpdated'] <= $t1;
		$complete = $responseValid && $updated && $target === $effect['expected'] && $lock && $metadata && $after['header']['state'] === $before['header']['state'] && $after['header']['owner'] === $before['header']['owner'];
		return ['status' => $complete ? 'applied' : ($changed ? 'partial' : 'uncertain'), 'nativeCallAttempted' => true,
			'target' => ['formId' => $r['form_id'], 'questionId' => $r['question_id']] + ($r['option'] ? ['optionId' => $r['option_id']] : []),
			'observed' => $observed, 'matchesRequestedEffect' => $matched,
			'lock' => ['nativeDurationSeconds' => 900, 'actorLockObserved' => $lock, 'lastUpdated' => $after['header']['lastUpdated'], 'lastUpdatedInAttemptWindowObserved' => $updated, 'unlockAttempted' => false],
			'confirmation' => $r['option'] ? null : ['selected_for_confirmation' => $after['header']['selector'] === $r['question_id'],
				'confirmation_enabled_on_form' => $after['header']['confirmationEnabled'], 'confirmation_allowed_by_app' => $after['header']['confirmationAllowedByApp'], 'eligible_recipient_question' => EditState::email($after['question']),
				'selection_empty_observed' => $after['header']['selector'] === null, 'expectedSelectionStateObserved' => $metadata],
			'notes' => EditMessages::receiptNotes()];
	}
}
