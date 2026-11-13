<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Markdown lines of the files_set_tag plan, in the user's language. */
final class TaggingPlanText {
	/** Static rendering only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $plan plan of files_set_tag
	 * @return list<string>|null the lines, or null when the plan lacks the path or the tag name
	 */
	public static function set(array $plan): ?array {
		$path = PlanFormatting::text($plan, 'path');
		$name = PlanFormatting::text(is_array($plan['tag'] ?? null) ? $plan['tag'] : [], 'name');
		if ($path === null || $name === null) {
			return null;
		}
		[$what, $tag] = [PlanFormatting::bold($path), PlanFormatting::bold($name)];
		$assign = ($plan['change'] ?? null) === 'assign';
		if (($plan['nothing_to_do'] ?? false) === true) {
			return [$assign ? Translator::t('Nothing to do: %s already has the tag %s.', [$what, $tag])
				: Translator::t('Nothing to do: %s does not have the tag %s.', [$what, $tag])];
		}
		return [
			$assign ? Translator::t('Add the tag %s to %s.', [$tag, $what]) : Translator::t('Remove the tag %s from %s.', [$tag, $what]),
			'',
			'- ' . Translator::t('Everyone who can see this item and this tag sees the change.'),
			'- ' . Translator::t('Rules that react to tags, such as automated tagging, retention or access control, may act on it.'),
			'- ' . Translator::t('Its content does not change and nothing is deleted.'),
			...PlanConsequences::sharing($plan),
		];
	}
}
