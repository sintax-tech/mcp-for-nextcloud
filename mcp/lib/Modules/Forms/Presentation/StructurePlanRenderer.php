<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Human-readable text of a structure plan; every user value stays quoted as untrusted data. */
final class StructurePlanRenderer {
	/** Plan fields shown, in this order. */
	private const FIELDS = ['action', 'target', 'requested', 'before', 'after', 'noChange', 'effects', 'irreversible', 'answers', 'shared',
		'confirmation', 'lock', 'nativeVersion'];

	/**
	 * @param array<string,mixed> $plan structure plan
	 * @return string rendered plan
	 */
	public function render(array $plan): string {
		$lines = [];
		foreach (self::FIELDS as $key) {
			$lines[] = '- ' . PlanText::quoted($key) . ': ' . self::value($plan[$key] ?? null);
		}
		foreach (array_merge($plan['notes'] ?? [], isset($plan['message']) ? [$plan['message']] : []) as $note) {
			$lines[] = '- ' . PlanText::quoted($note);
		}
		return implode("\n", $lines);
	}

	/**
	 * @param mixed $value plan value
	 * @return string quoted rendering
	 */
	private static function value(mixed $value): string {
		if (!is_array($value)) {
			return is_string($value) ? PlanText::quoted($value) : EditValues::json($value);
		}
		$items = [];
		foreach ($value as $key => $item) {
			$items[] = (array_is_list($value) ? '' : PlanText::quoted((string)$key) . ': ') . self::value($item);
		}
		return (array_is_list($value) ? '[' : '{') . implode(', ', $items) . (array_is_list($value) ? ']' : '}');
	}
}
