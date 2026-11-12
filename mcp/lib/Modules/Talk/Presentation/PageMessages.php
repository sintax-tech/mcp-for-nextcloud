<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Localized refusals of the paged Talk listing: page size and continuation cursor.
 *
 * Each method translates its unchanged English source text for the authenticated user through Translator.
 */
final class PageMessages {
	/**
	 * Prevents construction of this static presentation utility.
	 */
	private function __construct() {
	}

	/**
	 * Page size for talk_list_unread, between 1 and 50.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidPageLimit(): string {
		return Translator::t('limit must be a number between 1 and 50');
	}

	/**
	 * Opaque cursor for talk_list_unread that this tool did not issue.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidCursor(): string {
		return Translator::t('invalid cursor');
	}
}
