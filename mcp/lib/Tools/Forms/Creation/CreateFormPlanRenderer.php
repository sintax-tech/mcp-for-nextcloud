<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Creation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/** Shows every admitted field and option as inert, reversible text, without a shortening fallback. */
final class CreateFormPlanRenderer {
	/** Renders the complete structure with constant consequences, never caller-controlled Markdown.
	 * @param array<string,mixed> $plan Full creation plan.
	 * @return string|null Human-readable body or null for a non-creation plan.
	 */
	public function render(array $plan): ?string {
		if (($plan['action'] ?? null) !== CreateFormTools::TOOL || !is_array($plan['after'] ?? null)) { return null; }
		$request = $plan['after'];
		$lines = [Translator::t('Create a private form with this initial structure:'),
			'- ' . Translator::t('Title') . ': ' . PlanText::quoted($request['title']),
			'- ' . Translator::t('Description') . ': ' . PlanText::quoted($request['description'])];
		foreach ($request['questions'] as $i => $q) {
			$lines[] = '- ' . Translator::t('Question') . ' ' . ($i + 1) . ': ' . PlanText::quoted($q['text']) . ' (' . PlanText::inline($q['type'], 5000) . ')';
			$lines[] = '  - ' . Translator::t('Description') . ': ' . PlanText::quoted($q['description']);
			$lines[] = '  - ' . Translator::t('Required') . ': ' . ($q['required'] ? 'true' : 'false');
			$lines[] = '  - ' . Translator::t('Settings') . ':';
			foreach ($q['settings'] as $key => $value) {
				$lines[] = '    - ' . PlanText::quoted($key) . ': ' . PlanText::quoted(is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR));
			}
			foreach ($q['options'] as $category => $options) {
				$lines[] = '  - ' . PlanText::quoted($category) . ': ' . count($options);
				foreach ($options as $o => $text) { $lines[] = '    - ' . ($o + 1) . ': ' . PlanText::quoted($text); }
			}
		}
		$lines[] = '- ' . Translator::t('Forms version') . ': ' . PlanText::quoted((string)$plan['nativeVersion']);
		$lines[] = '- ' . Translator::t('Native defaults') . ': ' . PlanText::quoted(json_encode($plan['defaults'], JSON_THROW_ON_ERROR));
		foreach ($plan['notes'] as $note) { $lines[] = '- ' . PlanText::inline($note, 5000); }
		return implode("\n", $lines);
	}
}
