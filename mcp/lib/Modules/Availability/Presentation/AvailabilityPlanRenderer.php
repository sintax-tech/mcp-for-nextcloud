<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** The readable Markdown of an absence plan; the complete plan stays in structuredContent. */
final class AvailabilityPlanRenderer {
	/**
	 * @param string $tool tool name
	 * @param array<string,mixed> $plan the plan of that tool
	 * @return string|null the Markdown body, or null for the generic one
	 */
	public function render(string $tool, array $plan): ?string {
		if ($tool !== 'availability_set_absence') return null;
		try {
			$after = $plan['after'];
			$first = PlanText::inline((string)$after['first_day']);
			$last = PlanText::inline((string)$after['last_day']);
			$lines = [$plan['mode'] === 'create' ? AvailabilityMessages::planCreate($first, $last) : AvailabilityMessages::planUpdate($first, $last)];
			$details = [Translator::t('Time zone: %s, from %s to %s.', [PlanText::inline((string)$after['timezone']), PlanText::inline((string)$after['starts_at']), PlanText::inline((string)$after['ends_at'])]),
				Translator::t('Status: %s', [PlanText::inline((string)$after['status'], 100)]),
				Translator::t('Message: %s', [PlanText::inline((string)$after['message'], 400)]),
				is_array($after['replacement'])
					? Translator::t('Replacement: %s (%s)', [PlanText::inline((string)$after['replacement']['display_name']), PlanText::inline((string)$after['replacement']['user_id'])])
					: Translator::t('Replacement: none')];
			if (is_array($plan['before'] ?? null)) {
				$details[] = Translator::t('Currently: %s to %s, status %s.', [PlanText::inline((string)$plan['before']['first_day']),
					PlanText::inline((string)$plan['before']['last_day']), PlanText::inline((string)$plan['before']['status'], 100)]);
			}
			foreach ($details as $detail) $lines[] = '- ' . $detail;
			foreach ((array)($plan['notices'] ?? []) as $notice) $lines[] = '- ' . PlanText::inline((string)$notice, 400);
		} catch (\Throwable) {
			return null;
		}
		return $lines[0] . "\n\n" . implode("\n", array_slice($lines, 1));
	}
}
