<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Builds the note of a category-filtered task listing that matched nothing.
 *
 * A literal filter without a match answers with the same empty page an empty calendar would, which a
 * model reads as "there are no tasks"; the note tells the two apart. It quotes only the value the
 * caller sent and never names an existing category, so a label carried solely by protected tasks of a
 * shared calendar stays indistinguishable from one that does not exist.
 */
final class CategoryFilterNote {
	/**
	 * Builds the note for a completed scan without a single match.
	 *
	 * @param string $category category filter exactly as supplied
	 * @param bool $includeCompleted whether completed tasks were included in the scan
	 * @return string note in the language of the request
	 */
	public function forMiss(string $category, bool $includeCompleted): string {
		$note = Translator::t(
			'No visible task checked in this list has the category %s. Omit the category field to remove the category filter.',
			[json_encode($category, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]
		);
		if (!$includeCompleted) {
			$note .= ' ' . Translator::t(
				'Completed tasks were not checked. Set include_completed to true to include them.'
			);
		}
		if (preg_match('/[\p{L}\p{N}]/u', trim($category)) !== 1) {
			$note .= ' ' . Translator::t(
				'It has no letter or digit, so it looks like a placeholder rather than a real label.'
			);
		}
		return $note;
	}
}
