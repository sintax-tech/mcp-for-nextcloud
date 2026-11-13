<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Markdown of the status-type plan. Every stored or requested value is inert text. */
final class StatusPlanRenderer {
	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan plan from the handler
	 * @return string|null body, or null when this is not that plan
	 */
	public function render(string $tool, array $plan): ?string {
		if ($tool !== 'status_set_type' || !is_string($plan['after'] ?? null) || !is_string($plan['message'] ?? null)) {
			return null;
		}
		$before = is_string($plan['before'] ?? null)
			? PlanText::inline($plan['before'])
			: Translator::t('No status is configured.');
		return Translator::t('Change your own status type from %s to %s.', [$before, PlanText::inline($plan['after'])])
			. "\n- " . PlanText::inline($plan['message']);
	}
}
