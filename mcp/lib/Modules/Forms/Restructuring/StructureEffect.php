<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Integration\EditState;

/** Shape of a predicted structure effect, so every operation fills the same plan, plan_state and receipt fields. */
final class StructureEffect {
	private function __construct() {
	}

	/**
	 * @param array<string,mixed> $s fresh snapshot (the default selection is the current one)
	 * @param array<string,mixed> $fields operation fields: target, before, after, expected and, when they differ from the
	 *                                    defaults, noop, effects, irreversible, answers, selector, confirmation
	 * @return array<string,mixed> complete effect
	 */
	public static function of(array $s, array $fields): array {
		return $fields + ['noop' => false, 'effects' => [], 'irreversible' => false, 'answers' => ['mayExist' => true, 'counted' => false],
			'selector' => $s['header']['selector'], 'confirmation' => null];
	}

	/**
	 * Confirmation-email block of the plan (FO4), for an operation that can clear the selection of one question.
	 *
	 * @param array<string,mixed> $s fresh snapshot
	 * @param array<string,mixed> $question question before the operation
	 * @param int|null $selector predicted selection after the operation
	 * @return array<string,mixed> plan block
	 */
	public static function confirmation(array $s, array $question, ?int $selector): array {
		$selected = $s['header']['selector'] === $question['id'];
		return ['selected_for_confirmation' => $selected, 'confirmation_enabled_on_form' => $s['header']['confirmationEnabled'],
			'confirmation_allowed_by_app' => $s['header']['confirmationAllowedByApp'], 'eligible_recipient_question' => EditState::email($question),
			'selection_clear_predicted' => $selected && $selector === null];
	}

	/**
	 * @param array<string,mixed> $question projected question
	 * @return array{id: int, type: string, order: int, text: string} compact listing entry
	 */
	public static function listed(array $question): array {
		return ['id' => $question['id'], 'type' => $question['type'], 'order' => $question['order'], 'text' => $question['text']];
	}
}
