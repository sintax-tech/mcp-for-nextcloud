<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Text;

use OCA\Mcp\L10n\Translator;

/**
 * The refusals of a snippet that cannot locate one passage of a text, shared by every tool that changes a passage
 * (files_replace, notes_edit). The wording stays the one those tools have always sent.
 */
final class SnippetMessages {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @return string the snippet does not occur in the text
	 */
	public static function missing(): string {
		return Translator::t('The specified snippet does not appear in the file.');
	}

	/**
	 * @return string the snippet is not text that can be located in the file
	 */
	public static function notUtf8(): string {
		return Translator::t('The specified snippet is not valid UTF-8; send it exactly as it appears in the file.');
	}

	/**
	 * The search stops at the second occurrence, so the message gives no count.
	 *
	 * @return string the snippet occurs more than once
	 */
	public static function ambiguous(): string {
		return Translator::t('The specified snippet appears more than once in the file; provide a longer, unique snippet.');
	}
}
