<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/** Human plan body, with the exact admitted file made inert as Markdown and recovery claims kept bounded. */
final class DeletePlanText {
	/**
	 * @param array<string, mixed> $plan Concrete deletion plan, not untrusted instructions.
	 * @return list<string>|null Human-readable body, or null for malformed input.
	 */
	public static function lines(array $plan): ?array {
		if (!is_string($plan['path'] ?? null)) {
			return null;
		}
		if (($plan['nodeType'] ?? null) === 'folder') {
			return Folder\DeleteFolderPlanText::lines($plan);
		}
		return [Translator::t('Delete file') . ': ' . PlanText::strong($plan['path'], PHP_INT_MAX), DeleteMessages::supported(), ...self::notes($plan)];
	}

	/**
	 * Trash bin notes shared by the file and folder plans.
	 *
	 * @param array<string, mixed> $plan Concrete deletion plan.
	 * @return list<string> The try-the-trash-bin-first risk, plus the received-share or unknown-owner limitation when it applies.
	 */
	public static function notes(array $plan): array {
		$note = trim(TrashReceiptMessages::planNote(array_search($plan['trashDestination'] ?? null, TrashReceiptMessages::PLAN, true) ?: null));
		return [DeleteMessages::risk(), ...($note === '' ? [] : [$note])];
	}
}
