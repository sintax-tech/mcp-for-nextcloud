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
use OCA\Mcp\Modules\Calendar\Presentation\EventAlarmListing;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use RuntimeException;

/** `calendar_list_events`: the occurrences of a period, at most 366 days and 2 000 items. */
final class ListEvents implements Tool {
	/**
	 * @param ListEventsHandler $events the read
	 */
	public function __construct(
		private ListEventsHandler $events,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_list_events
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::definition('calendar_list_events', 'read', CalendarMessages::TOOL_LIST_EVENTS . ' ' . EventAlarmListing::NOTE, [
			'calendar' => CalendarSchema::calendar(CalendarMessages::propertyDescription('calendar-optional')),
			'from' => CalendarSchema::date(CalendarMessages::propertyDescription('from')),
			'to' => CalendarSchema::date(CalendarMessages::propertyDescription('to')),
		]);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the occurrences, plus {truncated, limit} when cut, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->events->handle($context->userId, $arguments);
	}
}
