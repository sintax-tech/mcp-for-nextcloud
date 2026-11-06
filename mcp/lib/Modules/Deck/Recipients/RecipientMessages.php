<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;

use OCA\Mcp\L10n\Translator;

/**
 * Sanitized recipient failures, exposed only after the source authorization boundary.
 */
final class RecipientMessages {
	/**
	 * Explain incomplete resolution without exposing an identity or partial count.
	 *
	 * @return string Localized complete-resolution refusal.
	 */
	public static function incomplete(): string {
		return Translator::t('The board recipients could not be fully resolved. Check the board sharing and group or team membership before trying again.');
	}

	/**
	 * Explain unsupported recipients without naming hidden or unresolved participants.
	 *
	 * @return string Localized supported-recipient refusal.
	 */
	public static function unsupported(): string {
		return Translator::t('The board has unsupported recipients. Only fully resolved local users, groups and teams are supported; email, contact and remote recipients prevent linking a file.');
	}

	/**
	 * Explain bounded expansion without returning a partial audience.
	 *
	 * @return string Localized expansion-limit refusal.
	 */
	public static function limit(): string {
		return Translator::t('The board recipients exceed the supported resolution limits. No partial recipient count is available. Reduce the board audience or try again later.');
	}
}
