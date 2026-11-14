<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The calendar read of a meeting dossier. Implemented by the Calendar module through calendar_list_events, so
 * recurrence and a private or confidential occurrence stay the reader's own rules. On an occurrence that reader
 * shows in full, the same object also contributes its participants and its explicit links. The public tool does
 * not. Without a binding the container fails.
 *
 * The call checks again, for the actor, everything calendar_list_events is checked for: the grant of its operation,
 * Guests and the Calendar app. It writes nothing.
 */
interface MeetingEventRead {
	/**
	 * Occurrences calendar_list_events would return, plus attendees and links when the reader shows the occurrence in full.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of calendar_list_events
	 * @return array<string, mixed> the result of calendar_list_events, with attendees and links on full occurrences
	 * @throws ToolFailure when the actor may not call calendar_list_events
	 * @throws \InvalidArgumentException on arguments calendar_list_events refuses
	 */
	public function events(string $userId, array $arguments): array;
}
