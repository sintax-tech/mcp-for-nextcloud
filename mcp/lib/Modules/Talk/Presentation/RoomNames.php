<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Localized names of the Talk conversations whose stored name is not what Talk shows.
 *
 * Talk names these rooms itself when it lists them; the texts follow its own wording.
 */
final class RoomNames {
	/**
	 * Prevents construction of this static presentation utility.
	 */
	private function __construct() {
	}

	/**
	 * The conversation in which Talk posts its own release notes.
	 * @return string The name in the language of the current user.
	 */
	public static function changelog(): string {
		return Translator::t('Talk updates');
	}

	/**
	 * The conversation opened to request the password of a shared file.
	 *
	 * @param string $stored the name Talk stored for the room, the shared file
	 * @return string The name in the language of the current user.
	 */
	public static function passwordRequest(string $stored): string {
		return Translator::t('Password request: %s', [$stored]);
	}
}
