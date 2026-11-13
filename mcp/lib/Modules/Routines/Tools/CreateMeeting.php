<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingHandler;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingPreview;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesSchema;

/** routines_create_meeting: a private Talk room, its agenda and the event with the room link, after one confirmation. */
final class CreateMeeting implements WriteTool {
	/**
	 * @param CreateMeetingHandler $handler the confirmed sequence
	 * @param CreateMeetingPreview $preview the plan of the three steps
	 */
	public function __construct(private CreateMeetingHandler $handler, private CreateMeetingPreview $preview) {
	}

	/** @return ToolDefinition the default-denied routines.meeting write */
	public static function definition(): ToolDefinition {
		return RoutinesSchema::meetingDefinition();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments with confirm: true
	 * @param ToolContext $context the actor
	 * @return array<string, mixed> the receipt, one item per step
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context the actor
	 * @return array<string, mixed> the plan, never a side effect
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($context->userId, $arguments);
	}
}
