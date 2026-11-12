<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation\DeckPlans;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Renders the confirmation text of the board label tools and the label line of a new card. */
final class LabelPlanText extends DeckPlanValues {
	/**
	 * @param array<string, mixed> $plan deck_create_label plan
	 * @return string escaped confirmation body
	 */
	public function renderCreate(array $plan): string {
		return implode("\n", [
			Translator::t('Create label %s on %s.', [PlanText::strong($plan['label']['title']), PlanText::em($plan['board']['board'])]),
			Translator::t('- Colour: %s', [self::color($plan['label']['color'])]),
			...$this->sharedLine($plan['board']),
		]);
	}

	/**
	 * @param array<string, mixed> $plan deck_update_label plan
	 * @return string escaped confirmation body
	 */
	public function renderUpdate(array $plan): string {
		$label = static fn (array $l): string => PlanText::inline($l['title']) . ' (' . self::color($l['color']) . ')';
		return implode("\n", [
			Translator::t('Change label %s on %s.', [PlanText::strong($plan['label']['title']), PlanText::em($plan['board']['board'])]),
			Translator::t('- Before: %s', [$label($plan['label'])]),
			Translator::t('- After: %s', [$label($plan['after'])]),
			Translator::n('- Carried by %n card, which shows the change.', '- Carried by %n cards, which show the change.', (int)$plan['cards']),
			...$this->sharedLine($plan['board']),
		]);
	}

	/**
	 * @param array<string, mixed> $plan deck_delete_label plan
	 * @return string escaped confirmation body
	 */
	public function renderDelete(array $plan): string {
		return implode("\n", [
			Translator::t('Delete label %s from %s.', [PlanText::strong($plan['label']['title']), PlanText::em($plan['board']['board'])]),
			Translator::n('- Removed from %n card, archived and deleted cards included.', '- Removed from %n cards, archived and deleted cards included.', (int)$plan['cards']),
			...$this->consequenceLine($plan),
			...$this->sharedLine($plan['board']),
		]);
	}

	/**
	 * @param mixed $labels Labels shown in a deck_create_card plan; new ones carry `new: true`.
	 * @return list<string> The label line, or nothing.
	 */
	public function cardLine(mixed $labels): array {
		if (!is_array($labels) || $labels === []) {
			return [];
		}
		$names = array_map(static fn (array $label): string => empty($label['new'])
			? PlanText::inline((string)$label['title'])
			: Translator::t('%s (new on the board)', [PlanText::inline((string)$label['title'])]), $labels);
		return [Translator::t('- Labels: %s', [implode(', ', $names)])];
	}

	/** @param string $color six hexadecimal digits, as Deck stores them @return string inert text */
	private static function color(string $color): string {
		return PlanText::inline('#' . $color);
	}
}
