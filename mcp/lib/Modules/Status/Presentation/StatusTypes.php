<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\L10n\Translator;

/** Status types accepted by StatusService::PRIORITY_ORDERED_STATUSES on NC 34 and 35. */
final class StatusTypes {
	/** @var list<string> native types, in the service's priority order */
	public const VALUES = ['online', 'away', 'dnd', 'busy', 'invisible', 'offline'];

	/** Static list only. */
	private function __construct() {
	}

	/** @return bool whether the type is one of the native values */
	public static function known(string $status): bool {
		return in_array($status, self::VALUES, true);
	}

	/** @throws \InvalidArgumentException when the type is not one of the six native values */
	public static function require(string $status): void {
		if (!self::known($status)) {
			throw new \InvalidArgumentException(Translator::t('The status type is not supported.'));
		}
	}
}
