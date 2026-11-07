<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\L10n\Translator;

/** User-facing failures for the image search tool, without backend details. */
final class ImageSearchMessages {
	/** @return string The same response for a missing or invisible tag. */
	public static function tagNotFound(): string {
		return Translator::t('Tag not found in Nextcloud.');
	}

	/** @return string Safe explanation for a failed image search. */
	public static function failed(): string {
		return Translator::t('Failed to search images in Nextcloud.');
	}
}
