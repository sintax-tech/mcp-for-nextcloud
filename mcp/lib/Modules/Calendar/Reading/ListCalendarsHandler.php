<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarFailures;
use RuntimeException;

/**
 * calendar_list_calendars: the event calendars the user owns or has been shared.
 */
final class ListCalendarsHandler {
	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 */
	public function __construct(private ScopedCalendarAccess $access) {}

	/**
	 * @param array<string, mixed> $arguments no arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} list of {name, path, owner, writable}
	 */
	private function read(array $arguments, string $userId): array {
		$out = [];
		foreach ($this->access->visible($userId) as $calendar) {
			$out[] = ['name' => $calendar->name, 'path' => $calendar->path, 'owner' => $calendar->ownerId, 'writable' => $calendar->writable];
		}
		return ToolSchema::result($out);
	}

	/**
	 * The read as the module answers it: a refusal the user can read becomes an error result.
	 *
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the MCP result, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function handle(string $userId, array $arguments): array {
		return CalendarFailures::run(fn (): array => $this->read($arguments, $userId));
	}
}
