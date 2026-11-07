<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Access\ResourceScope\NativeCalendar;
use OCA\Mcp\Access\ResourceScope\NativeCalendars;
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;

/** Adapts the native Calendar reader to the Access-owned resource enumeration contract. */
final class NativeCalendarsAdapter implements NativeCalendars {
	/** @param CalendarAccess $native Native reader, without MCP grant or scope filtering. */
	public function __construct(private CalendarAccess $native) {
	}

	/** {@inheritDoc} */
	public function visible(string $userId, string $component): array {
		return array_map(static fn (Calendar $calendar): NativeCalendar => new NativeCalendar($calendar->id, $calendar->name, $calendar->writable),
			$this->native->visible($userId, $component));
	}
}
