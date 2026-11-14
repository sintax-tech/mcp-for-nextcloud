<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Markdown lines of the files_add_comment plan, in the user's language. */
final class CommentingPlanText {
	/** Static rendering only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $plan plan of files_add_comment
	 * @return list<string>|null the lines, or null when the plan lacks the path or the text
	 */
	public static function add(array $plan): ?array {
		$path = PlanFormatting::text($plan, 'path');
		$text = PlanFormatting::text($plan, 'text');
		if ($path === null || $text === null) {
			return null;
		}
		$names = [];
		foreach (is_array($plan['notifies'] ?? null) ? $plan['notifies'] : [] as $person) {
			$names[] = PlanFormatting::bold((string)($person['display_name'] ?? '') . ' (' . (string)($person['id'] ?? '') . ')');
		}
		return [
			Translator::t('Add this comment to %s, as you:', [PlanFormatting::bold($path)]),
			'',
			PlanText::quote($text, 1000),
			'',
			'- ' . Translator::t('Everyone who can see this item sees the comment, with your name.'),
			'- ' . ($names === [] ? Translator::t('Nobody is mentioned, so nobody is notified.')
				: Translator::t('Mentioned people are notified: %s.', [implode(', ', $names)])),
			'- ' . Translator::t('Your read marker for the comments of this item moves to now: earlier comments count as read for you.'),
			'- ' . Translator::t('The comment cannot be edited or deleted through MCP.'),
			...PlanConsequences::sharing($plan),
		];
	}
}
