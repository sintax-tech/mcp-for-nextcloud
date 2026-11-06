<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Renders the recorded Files editing plan as Markdown lines. */
final class EditingPlanText {
	/**
	 * @return list<string>|null
	 * @param string $tool tool
	 * @param array<string, mixed> $plan plan
	 */
	public static function content(string $tool, array $plan): ?array {
		$path = PlanFormatting::text($plan, 'path');
		if ($path === null || !isset($plan['diff']) || !is_string($plan['diff'])) {
			return null;
		}
		$lines = [$tool === 'files_edit'
			? Translator::t('Overwrite the content of %s with the new text.', [PlanFormatting::bold($path)])
			: Translator::t('Replace a passage of %s.', [PlanFormatting::bold($path)]), ''];
		[$removed, $added] = self::changes($plan['diff']);
		if ($removed === [] && $added === []) {
			$lines[] = '- ' . Translator::t('The text does not change.');
		} else {
			$lines[] = '- ' . Translator::t('Lines removed: %s; lines added: %s.', [count($removed), count($added)]);
			if ($removed !== []) {
				$lines[] = '- ' . Translator::t('Before: %s', [PlanFormatting::quote(implode(' ', $removed))]);
			}
			if ($added !== []) {
				$lines[] = '- ' . Translator::t('After: %s', [PlanFormatting::quote(implode(' ', $added))]);
			}
		}
		$size = $plan['size'] ?? null;
		if (is_array($size) && isset($size['before'], $size['after'])) {
			$lines[] = '- ' . Translator::t('Size: %s → %s', [PlanFormatting::bytes((int)$size['before']), PlanFormatting::bytes((int)$size['after'])]);
		}
		return array_merge($lines, PlanConsequences::backup($plan), PlanConsequences::sharing($plan));
	}

	/**
	 * @param string $diff unified diff
	 * @return array{list<string>, list<string>} removed and added lines, without the markers
	 */
	private static function changes(string $diff): array {
		$removed = [];
		$added = [];
		foreach (explode("\n", $diff) as $line) {
			if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
				continue;
			}
			if (str_starts_with($line, '-')) {
				$removed[] = trim(substr($line, 1));
			} elseif (str_starts_with($line, '+')) {
				$added[] = trim(substr($line, 1));
			}
		}
		return [array_values(array_filter($removed, 'strlen')), array_values(array_filter($added, 'strlen'))];
	}
}
