<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Deletion;

use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;

/**
 * calendar_delete_event: deletes a whole event series into the CalDAV trash, never permanently.
 *
 * The DELETE goes through the CalDAV pipeline with If-Match set to the ETag that was just read, so
 * the node carries the event to the trash exactly as it does when the user deletes it in the app.
 */
final class DeleteEventDraft implements \OCA\Mcp\Modules\Calendar\Approval\CalendarDraft {
	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 * @param SharedGuard $guard confirmation gate for calendars of somebody else
	 * @param CalendarDav $dav the official write pipeline
	 * @param CalendarStore $store calendar storage port, used to confirm the object reached the trash
	 * @param EventRepository $events event lookup with classification and ETag checks
	 * @param TrashPolicy $trash CalDAV trash retention
	 * @param Scheduling $scheduling honest report of what was scheduled
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private SharedGuard $guard,
		private CalendarDav $dav,
		private CalendarStore $store,
		private EventRepository $events,
		private TrashPolicy $trash,
		private Scheduling $scheduling,
	) {}

	/**
	 * @param array{calendar: string, uid: string, etag?: string, send_invitations?: bool, confirm_shared?: bool} $arguments tool arguments, already validated against the schema
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} {uid, calendar, deleted, recoverable, scheduling}
	 * @throws CalendarException when not writable, protected, changed meanwhile or the trash is disabled
	 */
	public function execute(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		return is_array($prepared) ? $prepared : $prepared->dispatch();
	}

	/**
	 * Validates and constructs the write without dispatching, so the registry can show its plan first.
	 *
	 * @param array<string, mixed> $arguments raw tool arguments
	 * @param string $userId authenticated user id
	 * @return \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
	 */
	public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array {
		$calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
		if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
			return $confirmation;
		}
		if (!$this->trash->recoverable()) {
			throw CalendarException::blocked(CalendarMessages::trashDisabled());
		}
		$stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
		$notify = (bool)($arguments['send_invitations'] ?? false);
		return new \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite($calendar, null, $stored, null, function () use ($calendar, $stored, $notify, $userId, $arguments): array {
			$this->dav->delete($userId, $calendar->uri, $stored->uri, $stored->etag, $notify);
			// The re-read proves the node really went to the trash instead of being purged: the backend
			// keeps the row with a deleted-at timestamp under a renamed "-deleted" URI.
			if (!\OCA\Mcp\Modules\Calendar\Writing\TrashedObject::exists($this->store, $calendar->id, $stored->uri)) {
				throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
			}
			// There is no object left to read a SCHEDULE-STATUS from, so only the request is reported.
			return SchemaBuilder::result([
				'uid' => $arguments['uid'],
				'calendar' => $calendar->path,
				'deleted' => true,
				'recoverable' => true,
				'scheduling' => $this->scheduling->report($notify, null),
			]);
		});
	}
}
