<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventHandler;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventPreview;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema;
use RuntimeException;

/** `calendar_create_event`: a single, non-recurring event in a writable calendar. */
final class CreateEvent implements WriteTool {
	/**
	 * @param CreateEventHandler $creates the confirmed write
	 * @param CreateEventPreview $plans the plan of the write
	 */
	public function __construct(
		private CreateEventHandler $creates,
		private CreateEventPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_create_event
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::write(
			'calendar_create_event',
			'create',
			CalendarMessages::TOOL_CREATE_EVENT
				. \OCA\Mcp\Modules\Calendar\Presentation\AlarmMessages::CREATE
				. CalendarMessages::INVITES_OTHERS . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
			[
				'calendar' => CalendarSchema::calendar(),
				'summary' => CalendarSchema::text(CalendarMessages::propertyDescription('summary'), 1, 255),
				'start' => CalendarSchema::date(CalendarMessages::propertyDescription('start')),
				'end' => CalendarSchema::date(CalendarMessages::propertyDescription('end')),
				'allDay' => ['type' => 'boolean', 'default' => false, 'description' => CalendarMessages::PROP_ALL_DAY],
				'timeZone' => CalendarSchema::text(CalendarMessages::propertyDescription('timezone'), 1, 64),
				'location' => CalendarSchema::text(CalendarMessages::propertyDescription('location'), 0, 255),
				'description' => CalendarSchema::text(CalendarMessages::propertyDescription('description'), 0, 65536),
				'alarms' => CalendarSchema::alarms(),
				'attendees' => CalendarSchema::attendees(),
				'send_invitations' => CalendarSchema::sendInvitations(),
			],
			['calendar', 'summary', 'start', 'end'], true,
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the created event with its etag and scheduling, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->creates->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, with nothing written
	 * @throws CalendarException when the write would be refused
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($context->userId, $arguments);
	}
}
