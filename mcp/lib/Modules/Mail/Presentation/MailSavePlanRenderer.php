<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\L10n\Translator;

/** Makes sender-controlled metadata inert in the conversational plan, including the file a replacement changes. */
final class MailSavePlanRenderer {
	/**
	 * @param array<string,mixed> $plan Fresh metadata-only plan.
	 * @return string|null Human-readable plan, or null for an invalid shape.
	 */
	public function render(array $plan): ?string {
		if (!isset($plan['path'], $plan['budget'], $plan['consequence'])) {
			return null;
		}
		return PlanText::inline((string)$plan['path'], 4000)
			. "\n\n"
			. PlanText::inline((string)($plan['name'] ?? ''), 255)
			. ' · '
			. PlanText::inline((string)($plan['declaredMimeType'] ?? ''), 100)
			. "\n\n"
			. Translator::t('Size (bytes)')
			. ': ≤ '
			. PlanText::inline((string)($plan['declaredUpperBytes'] ?? ''), 30)
			. "\n\n"
			. Translator::t('Owner')
			. ': '
			. PlanText::inline((string)($plan['access']['ownerDisplayName'] ?? $plan['access']['owner'] ?? ''), 255)
			. "\n\n"
			. PlanText::inline((string)($plan['shared'][0]['message'] ?? ''), 800)
			. $this->replacement($plan)
			. "\n\n"
			. PlanText::strong((string)$plan['budget'], 300)
			. "\n\n"
			. PlanText::inline((string)$plan['consequence'], 800);
	}

	/**
	 * The file a replacement changes and the copy taken first, or the administrator suspension that skips it.
	 *
	 * @param array<string,mixed> $plan Fresh metadata-only plan.
	 * @return string Plan lines, empty for a new file.
	 */
	private function replacement(array $plan): string {
		if (!isset($plan['replaces'], $plan['backupNote'])) {
			return '';
		}
		$backup = (string)($plan['backup'] ?? '');
		return "\n\n"
			. PlanText::strong(MailSaveMessages::replaces(
				PlanText::flat((string)($plan['replaces']['size'] ?? ''), 30),
				PlanText::flat((string)($plan['replaces']['etag'] ?? ''), 200),
			), 400)
			. "\n\n"
			. ($backup === '' ? '' : PlanText::inline(MailSaveMessages::copyFolder(PlanText::flat($backup, 4000)), 4200) . ' ')
			. PlanText::inline((string)$plan['backupNote'], 800);
	}
}
