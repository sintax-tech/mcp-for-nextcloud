<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;

/** Safe scope refusal, without inviting the user to widen a privacy policy. */
final class AvailabilityMessages {
	/** Static messages only. */
	private function __construct() {}

	/** @return string localized explanation of the public API's collection limitation */
	public static function restricted(): string {
		return Translator::t('Availability cannot be checked with collection-restricted Calendar access: the native free/busy API cannot preserve that restriction.');
	}
}
