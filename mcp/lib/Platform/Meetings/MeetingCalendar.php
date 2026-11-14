<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Workflows\WorkflowStep;

/**
 * The Calendar step of a meeting: the event, created last. Implemented by the Calendar module through the work
 * classes of calendar_create_event, so the event goes through the CalDAV pipeline and its scheduling; without a
 * binding the container fails.
 *
 * Every method checks again, for the actor, everything calendar_create_event is checked for: the grant of its
 * operation, Guests, the Calendar app, the arguments, the resource scope, the native ACL and `confirm_shared` for a
 * calendar of somebody else. A plan writes nothing; a step runs at most once.
 */
interface MeetingCalendar {
	/**
	 * The plan of calendar_create_event, with its scheduling and sharing warnings.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the arguments of calendar_create_event, without confirm
	 * @return array<string, mixed> the plan of calendar_create_event, with nothing written
	 * @throws ToolFailure when the step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on arguments calendar_create_event refuses
	 */
	public function planEvent(string $userId, array $event): array;

	/**
	 * Creates the event as calendar_create_event does, once.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $event the arguments of calendar_create_event, without confirm
	 * @return WorkflowStep applied, failed (refused before the write) or uncertain (the event may exist)
	 * @throws \InvalidArgumentException on arguments calendar_create_event refuses
	 */
	public function createEvent(string $userId, array $event): WorkflowStep;
}
