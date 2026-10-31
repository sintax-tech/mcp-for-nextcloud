<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion\Folder;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Files\Deletion\DeleteMessages;
use OCA\Mcp\Tools\PlanText;

/** Renders complete folder counts without turning a partial inspection into a readable deletion plan. */
final class DeleteFolderPlanText {
	/**
	 * Renders only a well-shaped complete tree summary, keeping the source path inert.
	 * @param array<string,mixed> $plan Concrete admitted plan.
	 * @return list<string>|null Body lines, or null for malformed counts.
	 */
	public static function lines(array $plan): ?array {
		$tree = $plan['tree'] ?? null;
		if (!is_string($plan['path'] ?? null) || !is_array($tree)) {
			return null;
		}
		foreach (['nodes', 'files', 'folders', 'bytes'] as $key) {
			if (!is_int($tree[$key] ?? null) || $tree[$key] < 0) {
				return null;
			}
		}
		return [Translator::t('Delete folder') . ': ' . PlanText::strong($plan['path'], PHP_INT_MAX),
			Translator::t('Inspected tree: %s files, %s folders (including the source), %s bytes.', [$tree['files'], $tree['folders'], $tree['bytes']]),
			DeleteMessages::supported(), ...\OCA\Mcp\Tools\Files\Deletion\DeletePlanText::notes($plan)];
	}
}
