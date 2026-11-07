<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

/** Selects the complete renderer for the original three write tools. */
final class FormsPlanRenderer {
	/**
	 * @param string $tool published tool identity
	 * @param array<string,mixed> $plan complete inert write plan
	 * @return string|null original readable plan
	 */
	public function render(string $tool, array $plan): ?string {
		return match ($tool) {
			'forms_update_question', 'forms_update_option' => (new \OCA\Mcp\Modules\Forms\Presentation\EditPlanRenderer())->render($plan),
			'forms_add_question', 'forms_reorder_questions', 'forms_add_option', 'forms_reorder_options', 'forms_delete_question',
			'forms_delete_option', 'forms_change_question_type' => (new StructurePlanRenderer())->render($plan),
			default => (new \OCA\Mcp\Modules\Forms\Presentation\CreateFormPlanRenderer())->render($plan),
		};
	}
}
