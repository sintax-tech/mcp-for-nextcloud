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
		if ($tool === 'status_set_type') {
			return $this->type($plan);
		}
		if ($tool === 'status_set_message') {
			return $this->message($plan);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $plan type plan
	 * @return string|null body
	 */
	private function type(array $plan): ?string {
		if (!is_string($plan['after'] ?? null) || !is_string($plan['message'] ?? null)) {
			return null;
		}
		$before = is_string($plan['before'] ?? null)
			? PlanText::inline($plan['before'])
			: Translator::t('No status is configured.');
		return Translator::t('Change your own status type from %s to %s.', [$before, PlanText::inline($plan['after'])])
			. "\n- " . PlanText::inline($plan['message']);
	}

	/**
	 * @param array<string, mixed> $plan message plan
	 * @return string|null body
	 */
	private function message(array $plan): ?string {
		if (!is_string($plan['message'] ?? null) || !in_array($plan['mode'] ?? null, ['custom', 'predefined', 'clear'], true)) {
			return null;
		}
		$before = is_string($plan['before'] ?? null)
			? PlanText::inline($plan['before'])
			: Translator::t('No status is configured.');
		if ($plan['mode'] === 'clear') {
			$line = Translator::t('Remove your own status message.');
		} else {
			$after = is_string($plan['after'] ?? null)
				? PlanText::inline($plan['after'])
				: Translator::t('No status is configured.');
			$line = Translator::t('Change your own status message from %s to %s.', [$before, $after]);
		}
		$expires = is_int($plan['clear_at'] ?? null)
			? "\n- " . Translator::t('The message expires at unix time %d.', [$plan['clear_at']])
			: '';
		return $line . $expires . "\n- " . PlanText::inline($plan['message']);
	}
}
