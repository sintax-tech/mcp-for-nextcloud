<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Editing;

use OCA\Mcp\Tools\PlanText;

/**
 * What a confirmed edit shows when the plan no longer matches.
 *
 * The cell lines name only the requested changes. A question description or an option text can move outside those
 * cells, so the warning names that field and the plan carries its current value whole. The value is quoted in the
 * body, which the common envelope does not cut, because a warning message stops at 300 characters.
 */
final class EditPlanNotice {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The warning and the watched field. Both are added after the plan state, so neither enters the HMAC.
	 *
	 * @param array<string, mixed> $request Closed request.
	 * @param array<string, mixed> $state Authorized state found now.
	 * @return array<string, mixed> warnings and the complete watched field.
	 */
	public static function fields(array $request, array $state): array {
		$field = $request['option'] ? 'text' : 'description';
		$value = $request['option'] ? (string)($state['option']['text'] ?? '') : (string)($state['question']['description'] ?? '');
		return [
			'warnings' => [['message' => EditMessages::planChanged($field, $value)]],
			'watched' => ['name' => $field, 'value' => $value],
		];
	}

	/**
	 * One inert line for the watched field, complete.
	 *
	 * @param array<string, mixed> $plan Public plan.
	 * @return list<string> The line, or none when this plan has no watched field.
	 */
	public static function lines(array $plan): array {
		$watched = $plan['watched'] ?? null;
		if (!is_array($watched) || !is_string($watched['name'] ?? null) || !is_string($watched['value'] ?? null)) {
			return [];
		}
		return ['- ' . PlanText::quoted($watched['name']) . ': ' . PlanText::quoted($watched['value'])];
	}
}
