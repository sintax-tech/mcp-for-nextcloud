<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\ToolSchema;

/**
 * calendar_list_calendars: the event calendars the user owns or has been shared.
 */
final class ListCalendarsHandler implements CalendarTool {
	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 */
	public function __construct(private ScopedCalendarAccess $access) {}

	/**
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	public function definition(): array {
		return ToolSchema::definition('calendar_list_calendars', CalendarMessages::TOOL_LIST_CALENDARS, 'read', []);
	}

	/**
	 * @param array<string, mixed> $arguments no arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} list of {name, path, owner, writable}
	 */
	public function execute(array $arguments, string $userId): array {
		$out = [];
		foreach ($this->access->visible($userId) as $calendar) {
			$out[] = ['name' => $calendar->name, 'path' => $calendar->path, 'owner' => $calendar->ownerId, 'writable' => $calendar->writable];
		}
		return ToolSchema::result($out);
	}
}
