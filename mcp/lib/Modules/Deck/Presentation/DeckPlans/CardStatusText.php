<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation\DeckPlans;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Escapes every native name in the two status confirmation plans. */
final class CardStatusText {
	/** @param array<string,mixed> $plan Authorized scalar plan. @return string Human confirmation, not an execution receipt. */
	public function render(array $plan): string {
		$lines = ['**' . Translator::t('Change card status in Deck') . '**', '',
			PlanText::inline($plan['message'], 1000),
			Translator::t('Card: %s', [PlanText::inline($plan['card']['title'])]),
			Translator::t('Board: %s', [PlanText::inline($plan['board']['name'])]),
			Translator::t('Current list: %s', [PlanText::inline($plan['currentList']['title'])]),
			Translator::t('Done column: %s', [PlanText::inline($plan['doneColumn']['title'] ?? '—')]),
			Translator::t('Current completion (Unix UTC): %s', [PlanText::code($plan['card']['done'] === null ? 'null' : (string)$plan['card']['done'])]),
			Translator::t('Current archived state: %s', [PlanText::code($plan['card']['archived'] ? 'true' : 'false')]),
		];
		if ($plan['board']['shared']) {
			$lines[] = Translator::t('Shared board owner: %s', [PlanText::inline($plan['board']['ownerDisplayName'])]);
		}
		if ($plan['automaticMove']) {
			$lines[] = Translator::t('Native completion will also move the card to: %s', [PlanText::inline($plan['doneColumn']['title'])]);
		}
		if ($plan['noDestination'] !== null) { $lines[] = PlanText::inline($plan['noDestination'], 1000); }
		$lines[] = '';
		$lines[] = '- ' . PlanText::inline($plan['limitations'], 1500);
		return implode("\n", $lines);
	}
}
