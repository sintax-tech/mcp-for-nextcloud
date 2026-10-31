<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Move;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * Writes the plan of a move as the text the person reads before confirming: the message (its subject, sender and date
 * when the plan carries them, else its id), the folder it leaves and the folder it goes to (by path when the plan carries
 * them, else by id) and the notes of the plan. Subjects, names and paths are written inert, one line, nothing read as
 * Markdown, a link or HTML. A plan without its folders gives back null and the generic body is used.
 */
final class MailMovePlanRenderer {
	/**
	 * The Markdown body of a move plan.
	 *
	 * @param array<string, mixed> $plan the plan of {@see MailMovePlan}
	 * @return string|null the body, or null when the plan lacks what this renderer needs
	 */
	public function render(array $plan): ?string {
		$target = $plan['target'] ?? null;
		if (!is_array($target) || !is_array($plan['from'] ?? null) || !is_array($plan['to'] ?? null)) {
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
		$path = static fn (array $side): ?string => isset($side['path']) && is_string($side['path']) ? $side['path'] : null;
		$id = static fn (array $side): string => PlanText::code((string)($side['mailboxId'] ?? ''));
		$fromPath = $path($plan['from']);
		$toPath = $path($plan['to']);
		$lines[] = '- ' . Translator::t('Move: %1$s → %2$s', [
			$fromPath === null ? $id($plan['from']) : PlanText::inline($fromPath),
			$toPath === null ? $id($plan['to']) : PlanText::strong($toPath),
		]);
		if (isset($target['account']) && is_string($target['account'])) {
			$lines[] = Translator::t('Account: %s', [PlanText::inline($target['account'])]);
		}
		foreach ((array)($plan['notes'] ?? []) as $note) {
			if (is_string($note)) {
				$lines[] = PlanText::em($note);
			}
		}
		return implode("\n", $lines);
	}
}
