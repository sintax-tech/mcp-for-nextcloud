<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\PlanText;

/**
 * The readable body of a row write plan, inside the common envelope of {@see \OCA\Mcp\Tools\PlanRenderer}: the target,
 * then one line per cell with its column and the value before and after. When the plan changed, each numeric limit
 * follows on its own line. Every column title and value is text somebody else wrote and goes through {@see PlanText},
 * so none of it reads as Markdown or HTML or opens a line; a long value is shortened with its length, while the
 * structured plan keeps it whole.
 */
final class RowWritePlanRenderer {
	/** Characters of a value shown inline. */
	private const SHOWN = 120;

	/**
	 * The Markdown body of a plan.
	 *
	 * @param array<string, mixed> $plan the plan of {@see RowWritePlan}
	 * @return string|null the body, or null when the plan lacks what this renderer needs
	 */
	public function render(array $plan): ?string {
		$target = $plan['target'] ?? null;
		if (!is_array($target) || !is_array($plan['cells'] ?? null)) {
			return null;
		}
		$name = isset($target['viewId']) ? 'view ' . (int)$target['viewId'] : 'table ' . (int)($target['tableId'] ?? 0);
		if (isset($target['rowId'])) {
			$name .= ', row ' . (int)$target['rowId'];
		}
		$lines = [RowWriteMessages::planTarget(PlanText::code($name))];
		foreach ($plan['cells'] as $cell) {
			if (!is_array($cell)) {
				continue;
			}
			$column = PlanText::strong((string)($cell['column'] ?? '') === '' ? '#' . (int)($cell['columnId'] ?? 0) : (string)$cell['column']);
			$before = array_key_exists('before', $cell) ? self::value($cell['before']) : '—';
			$lines[] = '- ' . RowWriteMessages::planCell($column, $before, self::value($cell['after'] ?? null));
		}
		foreach ((array)($plan['notes'] ?? []) as $note) {
			if (is_string($note)) {
				$lines[] = '- ' . PlanText::em($note);
			}
		}
		foreach (RowWritePlanNotice::lines($plan) as $line) {
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/**
	 * One value, inert and shortened.
	 *
	 * @param mixed $value the value
	 * @return string the text
	 */
	private static function value(mixed $value): string {
		if ($value === null || $value === '' || $value === []) {
			return RowWriteMessages::emptyValue();
		}
		$text = is_string($value) ? $value : (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$length = mb_strlen($text);
		return $length > self::SHOWN
			? RowWriteMessages::longValue(PlanText::inline(mb_substr($text, 0, self::SHOWN)), $length)
			: PlanText::inline($text);
	}
}
