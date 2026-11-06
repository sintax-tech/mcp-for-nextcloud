<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;

/** Renders the backup and sharing consequences already recorded in a Files plan. */
final class PlanConsequences {
	/**
	 * @param array<string, mixed> $plan plan of a tool that copies the file before writing
	 * @return list<string> the line naming the backup folder, when the plan carries one
	 */
	public static function backup(array $plan): array {
		$backup = PlanFormatting::text($plan, 'backup');
		return $backup === null ? [] : ['- ' . Translator::t('A copy of the current file is saved first in %s.', [PlanFormatting::bold($backup)])];
	}

	/**
	 * @param array<string, mixed> $plan plan with an optional `shared` list of access descriptions
	 * @return list<string> who else is reached, and the extra confirmation when the plan asks for it
	 */
	public static function sharing(array $plan): array {
		$lines = [];
		foreach ((array)($plan['shared'] ?? []) as $info) {
			if (!is_array($info)) {
				continue;
			}
			$who = (string)($info['sharedBy'] ?? $info['ownerDisplayName'] ?? '');
			$line = match ($info['scope'] ?? null) {
				NodeAccessInfo::TEAM => Translator::t('It is in the team folder %s, so other members see the change.', [PlanFormatting::bold((string)($info['teamFolder'] ?? ''))]),
				NodeAccessInfo::EXTERNAL => Translator::t('It is on external storage.'),
				NodeAccessInfo::SHARED => Translator::t('It was shared with you by %s, so the change reaches them too.', [PlanFormatting::bold($who)]),
				default => null,
			};
			if ($line !== null) {
				$lines[] = '- ' . $line;
			}
		}
		if (($plan['requiresSharedConfirmation'] ?? false) === true) {
			$lines[] = '- ' . Translator::t('Because other people are affected, an extra confirmation of the shared content is needed.');
		}
		return $lines;
	}
}
