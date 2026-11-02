<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema;
use OCA\Mcp\Modules\Calendar\Reading\ListCalendarsHandler;
use RuntimeException;

/** `calendar_list_calendars`: the event calendars the user owns or has been shared. */
final class ListCalendars implements Tool {
	/**
	 * @param ListCalendarsHandler $calendars the read
	 */
	public function __construct(
		private ListCalendarsHandler $calendars,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_list_calendars
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::definition('calendar_list_calendars', 'read', CalendarMessages::TOOL_LIST_CALENDARS, []);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> list of {name, path, owner, writable}, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->calendars->handle($context->userId, $arguments);
	}
}
