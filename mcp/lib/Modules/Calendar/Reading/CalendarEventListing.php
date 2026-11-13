<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

/**
 * The calendar_list_events read, so a meeting port can call it without a second copy of its rules.
 */
interface CalendarEventListing {
	/**
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the MCP result of calendar_list_events
	 */
	public function handle(string $userId, array $arguments): array;
}
