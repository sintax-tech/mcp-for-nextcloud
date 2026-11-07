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
use OCA\Mcp\Modules\Calendar\Moving\MoveEventHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventPreview;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema;
use RuntimeException;

/** `calendar_move_event`: an event between two writable calendars of the same owner. */
final class MoveEvent implements WriteTool {
	/**
	 * @param MoveEventHandler $moves the confirmed write
	 * @param MoveEventPreview $plans the plan of the write
	 */
	public function __construct(
		private MoveEventHandler $moves,
		private MoveEventPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_move_event
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::write(
			'calendar_move_event',
			'move',
			CalendarMessages::TOOL_MOVE_EVENT . CalendarMessages::PARTICIPANTS_NOT_NOTIFIED . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
			[
				'calendar' => CalendarSchema::calendar(CalendarMessages::propertyDescription('source-calendar')),
				'uid' => CalendarSchema::uid(),
				'targetCalendar' => CalendarSchema::calendar(CalendarMessages::propertyDescription('target-calendar')),
				'etag' => CalendarSchema::etag(),
			],
			['calendar', 'uid', 'targetCalendar'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> {uid, etag, from, to}, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->moves->handle($context->userId, $arguments);
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
