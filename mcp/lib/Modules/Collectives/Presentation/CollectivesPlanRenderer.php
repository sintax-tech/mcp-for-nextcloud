<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** The readable Markdown of a Collectives plan; the complete plan stays in structuredContent. */
final class CollectivesPlanRenderer {
	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan of that tool
	 * @return string|null the Markdown body, or null for the generic one
	 */
	public function render(string $tool, array $plan): ?string {
		try {
			$lines = match ($tool) {
				'collectives_create' => [Translator::t('Create page **%s** under *%s*.', [PlanText::inline((string)$plan['page_title']), PlanText::inline((string)$plan['parent']['title'])])],
				'collectives_edit' => [Translator::t('Change page **%s** (%s): %d bytes now, %d after; the diff is in the plan.',
					[PlanText::inline((string)$plan['page']['title']), PlanText::inline((string)$plan['mode']), (int)$plan['bytes_before'], (int)$plan['bytes_after']])],
				'collectives_move' => [Translator::t('Move page **%s** under *%s* as **%s**.', [PlanText::inline((string)$plan['page']['title']),
					PlanText::inline((string)$plan['to']['parent_title']), PlanText::inline((string)$plan['to']['title'])])],
				default => null,
			};
		} catch (\Throwable) {
			return null;
		}
		if ($lines === null) {
			return null;
		}
		foreach ((array)($plan['notices'] ?? []) as $notice) {
			$lines[] = '- ' . PlanText::inline((string)$notice, 400);
		}
		return $lines[0] . "\n\n" . implode("\n", array_slice($lines, 1));
	}
}
