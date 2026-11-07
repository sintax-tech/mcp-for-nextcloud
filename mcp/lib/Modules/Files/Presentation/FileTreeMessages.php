<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Neutral localized refusals shared by file tree preflight for copy, move, batch and undo. */
final class FileTreeMessages {
	/** Static messages only. */
	private function __construct() {
	}

	/**
	 * @param int $limit Maximum validated nodes or projected names in this boundary.
	 * @return string Neutral preflight refusal, before any operation effects.
	 */
	public static function tooManyNodes(int $limit): string {
		return Translator::t('The operation would exceed %s items; nothing was changed.', [$limit]);
	}
}
