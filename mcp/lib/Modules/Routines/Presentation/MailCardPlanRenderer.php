<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** The readable Markdown of a mail card plan; the complete plan of each step stays in structuredContent. */
final class MailCardPlanRenderer {
	/**
	 * @param array<string, mixed> $plan the plan of routines_create_mail_card
	 * @return string|null the Markdown body, or null for the generic one
	 */
	public function render(array $plan): ?string {
		try {
			[$file, $card] = array_column($plan['steps'], 'plan');
			$lines = [Translator::t('From the email %s, in two steps, in this order:', [PlanText::strong((string)$plan['email']['message']['subject'])]),
				'1. ' . Translator::t('Save the attachment %s as the new file %s.', [PlanText::strong((string)($file['name'] ?? $file['attachmentId'])), PlanText::strong((string)$file['path'], 1000)]),
				'2. ' . Translator::t('Create the card %s in the list %s of the board %s, owned by %s, with this text:', [
					PlanText::strong((string)$card['card']['title']), PlanText::inline((string)($card['destination']['list'] ?? '')),
					PlanText::inline((string)$card['destination']['board']), PlanText::inline((string)$card['destination']['ownerDisplayName'])]),
				'',
				PlanText::quoted((string)$card['card']['description']),
				'',
				self::readers($plan['board_readers'] ?? []),
				''];
			foreach ((array)($plan['notices'] ?? []) as $notice) {
				$lines[] = '- ' . PlanText::inline((string)$notice, 400);
			}
		} catch (\Throwable) {
			return null;
		}
		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $readers who can read the board, as the plan shows it
	 * @return string one sentence naming them
	 */
	private static function readers(array $readers): string {
		if (($readers['readable'] ?? false) !== true) {
			return MailCardMessages::audienceUnknown();
		}
		$names = array_map(static fn (array $entry): string => PlanText::inline((string)$entry['displayName']) . ' (' . $entry['type'] . ')', (array)$readers['entries']);
		return $names === []
			? Translator::t('Readers of the board: only its owner %s.', [PlanText::inline((string)$readers['ownerDisplayName'])])
			: Translator::t('Readers of the board: its owner %s and %s.', [PlanText::inline((string)$readers['ownerDisplayName']), implode(', ', $names)]);
	}
}
