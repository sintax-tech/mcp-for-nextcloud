<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\Modules\Mail\Editing\MailFlagPlan;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * Writes the plan of a flag change as the text the person reads before confirming: the message (its subject, sender
 * and date when the plan carries them, else its id), its folder and account when the plan carries them, and each flag
 * that changes or is already as asked. Subjects and names are the senders' text: they are written inert, one line,
 * nothing read as Markdown, a link or HTML. A plan without its changes gives back null and the generic body is used.
 */
final class MailFlagPlanRenderer {
	/**
	 * The Markdown body of a flag plan.
	 *
	 * @param array<string, mixed> $plan the plan of {@see MailFlagPlan}
	 * @return string|null the body, or null when the plan lacks what this renderer needs
	 */
	public function render(array $plan): ?string {
		$target = $plan['target'] ?? null;
		if (!is_array($target) || !is_array($plan['changes'] ?? null)) {
			return null;
		}
		$lines = [];
		$lines[] = isset($target['subject']) && is_string($target['subject'])
			? Translator::t('Message: %s', [PlanText::strong($target['subject'] === '' ? '—' : $target['subject'])])
				. (isset($target['date']) && is_string($target['date']) ? ' · ' . PlanText::inline($target['date']) : '')
			: Translator::t('Message: %s', [PlanText::code((string)($target['messageId'] ?? ''))]);
		$sender = $target['from']['addresses'][0] ?? null;
		if (is_array($sender)) {
			$lines[] = Translator::t('From: %s', [PlanText::inline(trim((string)($sender['name'] ?? '') . ' <' . (string)($sender['email'] ?? '') . '>'))]);
		}
		if (isset($target['folder']) && is_string($target['folder'])) {
			$lines[] = Translator::t('Folder: %s', [PlanText::inline($target['folder'])]) . (isset($target['account']) && is_string($target['account'])
				? ' · ' . Translator::t('account %s', [PlanText::inline($target['account'])]) : '');
		}
		foreach ($plan['changes'] as $change) {
			if (is_array($change) && isset($change['to']) && is_string($change['to'])) {
				$from = isset($change['from']) && is_string($change['from']) ? PlanText::inline($change['from']) : '?';
				$lines[] = '- ' . Translator::t('Change: %1$s → %2$s', [$from, PlanText::strong($change['to'])]);
			}
		}
		foreach ((array)($plan['unchanged'] ?? []) as $same) {
			if (is_array($same) && isset($same['state']) && is_string($same['state'])) {
				$lines[] = '- ' . Translator::t('Already %s; left as it is', [PlanText::inline($same['state'])]);
			}
		}
		return implode("\n", $lines);
	}
}
