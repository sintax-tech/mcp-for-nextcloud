<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/**
 * Renders a send plan as Markdown for the user: the sender, each recipient field, the subject, the text as a quote and
 * the notes, the first of them in bold. Every value is the caller's or the account's text and is made inert
 * ({@see PlanText}); nothing in it is read as Markdown, HTML or a link.
 */
final class MailSendPlanRenderer {
	/** Characters of the text shown in the rendered plan; the plan data carries all of it. */
	private const BODY_SHOWN = 2000;

	/**
	 * The Markdown body of a send plan.
	 *
	 * @param array<string, mixed> $plan the plan of {@see MailSendPlan}
	 * @return string|null the body, or null when the plan lacks what this renderer needs
	 */
	public function render(array $plan): ?string {
		$target = $plan['target'] ?? null;
		$message = $plan['mail'] ?? null;
		if (!is_array($target) || !is_array($message)) {
			return null;
		}
		$lines = [];
		$notes = array_values(array_filter((array)($plan['notes'] ?? []), 'is_string'));
		if ($notes !== []) {
			$lines[] = PlanText::strong(array_shift($notes), 300);
			$lines[] = '';
		}
		$lines[] = isset($target['from']) && is_string($target['from'])
			? Translator::t('Send from %s', [PlanText::inline($target['from'], 254)])
			: Translator::t('Send from account %s', [PlanText::code((string)($target['accountId'] ?? ''))]);
		foreach (['to', 'cc', 'bcc'] as $field) {
			$addresses = array_values(array_filter((array)($message[$field] ?? []), 'is_string'));
			if ($addresses === []) {
				continue;
			}
			$list = implode(', ', array_map(static fn (string $a): string => PlanText::inline($a, 254), $addresses));
			$lines[] = '- ' . match ($field) {
				'to' => Translator::t('To: %s', [$list]),
				'cc' => Translator::t('Cc: %s', [$list]),
				default => Translator::t('Bcc: %s', [$list]),
			};
		}
		$subject = is_string($message['subject'] ?? null) ? $message['subject'] : '';
		$lines[] = '- ' . Translator::t('Subject: %s', [$subject === '' ? '—' : PlanText::strong($subject, 255)]);
		$body = is_string($message['body'] ?? null) ? $message['body'] : '';
		if ($body !== '') {
			$lines[] = '';
			$lines[] = PlanText::quote($body, self::BODY_SHOWN);
			$lines[] = '';
		}
		foreach ($notes as $note) {
			$lines[] = PlanText::em($note, 300);
		}
		return implode("\n", $lines);
	}
}
