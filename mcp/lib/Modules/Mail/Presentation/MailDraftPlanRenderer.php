<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\Modules\Mail\Creation\MailDraftPlan;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * Renders a draft plan as Markdown for the user: the account, each recipient field, the subject, the text as a quote and
 * the notes. Every value is the caller's text and is made inert ({@see PlanText}); nothing in it is read as Markdown,
 * HTML or a link.
 */
final class MailDraftPlanRenderer {
	/** Characters of the text shown in the rendered plan; the plan data carries all of it. */
	private const BODY_SHOWN = 2000;

	/**
	 * The Markdown body of a draft plan.
	 *
	 * @param array<string, mixed> $plan the plan of {@see MailDraftPlan}
	 * @return string|null the body, or null when the plan lacks what this renderer needs
	 */
	public function render(array $plan): ?string {
		$target = $plan['target'] ?? null;
		$draft = $plan['draft'] ?? null;
		if (!is_array($target) || !is_array($draft)) {
			return null;
		}
		$lines = [];
		$lines[] = Translator::t('New draft in %s', [isset($target['draftsPath']) && is_string($target['draftsPath'])
			? PlanText::strong($target['draftsPath']) : PlanText::code((string)($target['draftsMailboxId'] ?? ''))]);
		if (isset($target['account']) && is_string($target['account'])) {
			$lines[] = Translator::t('Account: %s', [PlanText::inline($target['account'])]);
		}
		foreach (['to', 'cc', 'bcc'] as $field) {
			$addresses = array_values(array_filter((array)($draft[$field] ?? []), 'is_string'));
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
		$subject = is_string($draft['subject'] ?? null) ? $draft['subject'] : '';
		$lines[] = '- ' . Translator::t('Subject: %s', [$subject === '' ? '—' : PlanText::strong($subject, 255)]);
		$body = is_string($draft['body'] ?? null) ? $draft['body'] : '';
		if (($draft['html'] ?? false) === true) {
			$lines = array_merge($lines, MailDraftHtmlView::lines($draft));
		} elseif ($body !== '') {
			$lines[] = '';
			$lines[] = PlanText::quote($body, self::BODY_SHOWN);
			$lines[] = '';
		}
		foreach ((array)($plan['notes'] ?? []) as $note) {
			if (is_string($note)) {
				$lines[] = PlanText::em($note);
			}
		}
		return implode("\n", $lines);
	}
}
