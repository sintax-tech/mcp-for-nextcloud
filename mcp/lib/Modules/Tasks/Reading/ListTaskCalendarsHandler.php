<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** Lists the same visible VTODO calendars as the legacy Tasks module. */
final class ListTaskCalendarsHandler {
	/**
	 * @param ScopedCalendarLookup $access native calendar visibility narrowed by the operation scope
	 */
	public function __construct(private ScopedCalendarLookup $access) {
	}

	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated UID
	 * @return array<string, mixed> MCP result of the ordered calendar list
	 */
	public function handle(array $arguments, string $userId): array {
		return ToolResult::json(array_map(static fn ($calendar) => (array) $calendar, $this->access->visible($userId, 'VTODO')));
	}
}
