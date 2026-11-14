<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Readable body of the one-id dismiss plan. Every third-party string is escaped. */
final class NotificationsPlanRenderer {
	/**
	 * @param string $tool published tool name
	 * @param array<string,mixed> $plan preview returned by the tool
	 * @return string|null plan body, or null when this is not the dismiss plan
	 */
	public function render(string $tool, array $plan): ?string {
		if ($tool !== 'notifications_dismiss' || !is_string($plan['message'] ?? null) || !is_string($plan['warning'] ?? null)) {
			return null;
		}
		$lines = [PlanText::inline($plan['message']), PlanText::inline($plan['warning'])];
		if (is_string($plan['app'] ?? null) || is_string($plan['datetime'] ?? null)) {
			$lines[] = Translator::t('Notification %s from %s, dated %s.', [
				PlanText::code(is_scalar($plan['id'] ?? null) ? (string)$plan['id'] : ''),
				PlanText::inline(is_string($plan['app'] ?? null) ? $plan['app'] : ''),
				PlanText::inline(is_string($plan['datetime'] ?? null) ? $plan['datetime'] : ''),
			]);
		}
		return implode("\n", $lines);
	}
}
