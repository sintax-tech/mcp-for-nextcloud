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
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventHandler;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventPreview;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarSchema;
use RuntimeException;

/** `calendar_delete_event`: an event, the entire series, into the calendar trash bin. */
final class DeleteEvent implements WriteTool {
	/**
	 * @param DeleteEventHandler $deletions the confirmed write
	 * @param DeleteEventPreview $plans the plan of the write
	 */
	public function __construct(
		private DeleteEventHandler $deletions,
		private DeleteEventPreview $plans,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of calendar_delete_event
	 */
	public static function definition(): ToolDefinition {
		return CalendarSchema::write(
			'calendar_delete_event',
			'delete',
			CalendarMessages::TOOL_DELETE_EVENT
				. CalendarMessages::SEND_INVITATIONS_NOTE . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
			[
				'calendar' => CalendarSchema::calendar(),
				'uid' => CalendarSchema::uid(),
				'etag' => CalendarSchema::etag(),
				'send_invitations' => CalendarSchema::sendInvitations(),
			],
			['calendar', 'uid'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> {uid, calendar, deleted, recoverable, scheduling}, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->deletions->handle($context->userId, $arguments);
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
