<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\Modules\Forms\Editing\EditValues;

use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Renders every admitted plan field reversibly and inertly, without the default inline truncation. */
final class EditPlanRenderer {
	/** Shows complete structured values and consequences with no active Markdown from user text.
	 * @param array<string,mixed> $plan Complete public plan.
	 * @return string Full readable body.
	 */
	public function render(array $plan): string {
		$lines = [];
		foreach (['action','target','before','after','requested','settingEffects','shared','confirmation','nativeVersion'] as $key) { $lines[] = '- ' . PlanText::quoted($key) . ': ' . self::value($plan[$key]); }
		foreach ($plan['notes'] as $note) { $lines[] = '- ' . PlanText::quoted($note); }
		foreach (EditPlanNotice::lines($plan) as $line) { $lines[] = $line; }
		return implode("\n", $lines);
	}
	/** Renders JSON-like structure while quoting each text once, retaining complete hostile leaf values inertly.
	 * @param mixed $value Admitted public plan value.
	 * @return string Complete reversible value presentation.
	 */
	private static function value(mixed $value): string {
		if (!is_array($value)) { return is_string($value) ? PlanText::quoted($value) : EditValues::json($value); }
		$items = [];
		foreach ($value as $key => $item) { $items[] = (array_is_list($value) ? '' : PlanText::quoted((string)$key) . ': ') . self::value($item); }
		return (array_is_list($value) ? '[' : '{') . implode(', ', $items) . (array_is_list($value) ? ']' : '}');
	}

}
