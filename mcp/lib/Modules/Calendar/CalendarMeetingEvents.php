<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Calendar\Integration\MeetingOccurrenceFacts;
use OCA\Mcp\Modules\Calendar\Reading\CalendarEventListing;
use OCA\Mcp\Modules\Calendar\Tools\ListEvents;
use OCA\Mcp\Platform\Meetings\MeetingEventRead;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The calendar read of a meeting dossier, through the work class of calendar_list_events.
 *
 * The registry's own check (grant, Guests and the Calendar app) runs again, then the published schema. Recurrence
 * and a private or confidential occurrence stay inside that reader. Attendees and explicit links are copied from
 * the same objects, and only when that reader shows the occurrence in full. The call does not write.
 */
final class CalendarMeetingEvents implements MeetingEventRead {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param CalendarEventListing $events the read of calendar_list_events
	 * @param MeetingOccurrenceFacts $facts attendees and links the public tool does not return
	 */
	public function __construct(
		private ToolAccess $access,
		private CalendarEventListing $events,
		private MeetingOccurrenceFacts $facts,
	) {
	}

	/** {@inheritDoc} */
	public function events(string $userId, array $arguments): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(ListEvents::definition()->name));
		}
		$arguments = ArgumentValidator::validate(ListEvents::definition()->inputSchema, $arguments);
		$result = $this->events->handle($userId, $arguments);
		if (($result['isError'] ?? false) === true) {
			return $result;
		}
		return $this->facts->apply($userId, $arguments, $result);
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call calendar_list_events now
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(ListEvents::definition()->toArray(), $userId);
	}
}
