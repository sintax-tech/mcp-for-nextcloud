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
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventHandler;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventPreview;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema;
use RuntimeException;

/** `calendar_update_event`: the title, place, description, dates or guests of an event. */
final class UpdateEvent implements WriteTool {
	/**
	 * @param UpdateEventHandler $updates the confirmed write
	 * @param UpdateEventPreview $plans the plan of the write
	 */
	public function __construct(
		private UpdateEventHandler $updates,
		private UpdateEventPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_update_event
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::write(
			'calendar_update_event',
			'edit',
			CalendarMessages::TOOL_UPDATE_EVENT
				. CalendarMessages::INVITES_OTHERS . CalendarMessages::SEND_INVITATIONS_NOTE . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
			[
				'calendar' => CalendarSchema::calendar(),
				'uid' => CalendarSchema::uid(),
				'summary' => CalendarSchema::text(CalendarMessages::propertyDescription('updated-summary'), 1, 255),
				'start' => CalendarSchema::date(CalendarMessages::propertyDescription('updated-start')),
				'end' => CalendarSchema::date(CalendarMessages::propertyDescription('updated-end')),
				'allDay' => ['type' => 'boolean', 'description' => CalendarMessages::PROP_ALL_DAY],
				'timeZone' => CalendarSchema::text(CalendarMessages::propertyDescription('timezone'), 1, 64),
				'location' => CalendarSchema::text(CalendarMessages::propertyDescription('updated-location'), 0, 255),
				'description' => CalendarSchema::text(CalendarMessages::propertyDescription('updated-description'), 0, 65536),
				'attendees' => CalendarSchema::attendees(true),
				'send_invitations' => CalendarSchema::sendInvitations(),
				'etag' => CalendarSchema::etag(),
			],
			['calendar', 'uid'], true,
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the updated event with its etag and scheduling, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->updates->handle($context->userId, $arguments);
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
