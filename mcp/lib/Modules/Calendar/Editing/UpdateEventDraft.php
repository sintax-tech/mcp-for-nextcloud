<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Editing;

use OCA\Mcp\Modules\Calendar\Composing\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Composing\EventBuilder;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Composing\EventTiming;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Component\VEvent;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;

/**
 * calendar_update_event: changes text and, for non-recurring events, timing of the master VEVENT.
 *
 * The write itself goes through the CalDAV pipeline (CalendarDav) with If-Match always set to the
 * ETag that was just read, so a concurrent edit by somebody else loses instead of overwriting.
 */
final class UpdateEventDraft implements \OCA\Mcp\Modules\Calendar\Approval\CalendarDraft {
	/** Arguments that change the event text. */
	private const TEXT_FIELDS = ['summary', 'location', 'description'];
	/** Arguments that change the event timing. */
	private const TIMING_FIELDS = ['start', 'end', 'allDay', 'timeZone'];

	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 * @param SharedGuard $guard confirmation gate for calendars of somebody else
	 * @param CalendarDav $dav the official write pipeline
	 * @param CalendarStore $store calendar storage port, used to read the event back
	 * @param EventRepository $events event lookup with classification and ETag checks
	 * @param EventBuilder $builder VEVENT changes
	 * @param EventMapper $mapper output mapping
	 * @param DateInput $dates date parsing
	 * @param AttendeeResolver $attendees internal guest list
	 * @param Scheduling $scheduling honest report of what was scheduled
	 * @param ITimeFactory $time clock
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private SharedGuard $guard,
		private CalendarDav $dav,
		private CalendarStore $store,
		private EventRepository $events,
		private EventBuilder $builder,
		private EventMapper $mapper,
		private DateInput $dates,
		private AttendeeResolver $attendees,
		private Scheduling $scheduling,
		private ITimeFactory $time,
	) {}

	/**
	 * @param array{calendar: string, uid: string, summary?: string, start?: string, end?: string, allDay?: bool, timeZone?: string, location?: string, description?: string, attendees?: list<string>, send_invitations?: bool, etag?: string, confirm_shared?: bool} $arguments tool arguments, already validated against the schema
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} updated event item plus etag and scheduling
	 * @throws CalendarException when not visible, not writable, changed meanwhile or a recurring timing change
	 * @throws CalendarArgumentException when nothing is given, dates are invalid or the guest list is refused
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
		$timingChanges = array_intersect_key($arguments, array_flip(self::TIMING_FIELDS));
		$textChanges = array_intersect_key($arguments, array_flip(self::TEXT_FIELDS));
		$guestChanges = array_key_exists('attendees', $arguments);
		if ($timingChanges === [] && $textChanges === [] && !$guestChanges && !array_key_exists('alarms', $arguments)) {
			throw new CalendarArgumentException(CalendarMessages::noFieldGiven());
		}
		$calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
		if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
			return $confirmation;
		}
		$stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
		$before = new \OCA\Mcp\Modules\Calendar\Locating\StoredEvent($stored->id, $stored->uri, $stored->etag, clone $stored->vcalendar);
		$master = $stored->master() ?? throw CalendarException::notFound();
		if ($timingChanges !== []) {
			if ($stored->recurring()) {
				throw CalendarException::conflict(CalendarMessages::recurringTiming());
			}
			$this->builder->setTiming($master, $this->mergeTiming($master, $timingChanges));
		}
		$this->builder->setText($master, $textChanges);
		if ($guestChanges) {
			$this->replaceGuests($master, $stored, $arguments['attendees'] ?? [], $userId);
		}
		\OCA\Mcp\Modules\Calendar\Composing\EventAlarms::apply($master, $arguments);
		$this->builder->touch($master, $this->time->now());
		$notify = (bool)($arguments['send_invitations'] ?? false);
		// If-Match always carries the ETag just read, even when the client sent none, so a change
		// made between the read and the write loses instead of being overwritten (an ETag mismatch aborts the update).
		return new \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite($calendar, null, $before, $stored->vcalendar, function () use ($calendar, $stored, $notify, $userId, $arguments, $before): array {
			if (array_key_exists('alarms', $arguments)) {
				return \OCA\Mcp\Modules\Calendar\Writing\AlarmReceipt::dispatch($stored->vcalendar, $before->vcalendar, $this->events,
					fn () => $this->dav->update($userId, $calendar->uri, $stored->uri, $stored->etag, $stored->vcalendar->serialize(), $notify),
					fn () => $this->store->object($calendar->id, $stored->uri),
					function ($written, $row) use ($calendar, $notify): array {
						$event = (new \OCA\Mcp\Modules\Calendar\Locating\StoredEvent(0, '', '', $written))->master();
						$timing = $this->builder->timing($event);
						return $this->mapper->toItem($event, $timing['start'], $timing['end'], $calendar, Classification::FULL)
							+ ['etag' => $row['etag'], 'scheduling' => $this->scheduling->report($notify, $written)];
					},
				);
			}
			$this->dav->update($userId, $calendar->uri, $stored->uri, $stored->etag, $stored->vcalendar->serialize(), $notify);
			$row = $this->store->object($calendar->id, $stored->uri)
				?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
			$written = $this->events->parse($row['data']) ?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
			$timing = $this->builder->timing($written->VEVENT);
			$item = $this->mapper->toItem($written->VEVENT, $timing['start'], $timing['end'], $calendar, Classification::FULL);
			return SchemaBuilder::result($item + [
				'etag' => $row['etag'],
				'scheduling' => $this->scheduling->report($notify, $written),
			]);
		});
	}

	/**
	 * Replaces the guest list of a series or of somebody else's event only when the caller may.
	 *
	 * @param VEvent $master event being changed
	 * @param \OCA\Mcp\Modules\Calendar\Locating\StoredEvent $stored event as read from the store
	 * @param list<string> $uids internal account ids sent by the caller; an empty list removes every guest
	 * @param string $userId acting user
	 * @return void the event is changed in place
	 * @throws CalendarArgumentException when a UID cannot be invited
	 * @throws CalendarException when the guest list may not be changed here
	 */
	private function replaceGuests(VEvent $master, \OCA\Mcp\Modules\Calendar\Locating\StoredEvent $stored, array $uids, string $userId): void {
		if ($stored->recurring()) {
			throw CalendarException::blocked(CalendarMessages::recurringAttendees());
		}
		if (!$this->isOrganizer($master, $userId)) {
			throw CalendarException::blocked(CalendarMessages::notOrganizer());
		}
		if ($uids === []) {
			unset($master->ATTENDEE);
			return;
		}
		$guests = $this->attendees->resolve($uids, $userId);
		$this->builder->setAttendees($master, $guests, $this->attendees->organizer($userId)['email'], $this->attendees->organizer($userId)['displayName']);
	}

	/**
	 * @param VEvent $event event to inspect
	 * @param string $userId acting user
	 * @return bool whether the acting account is the ORGANIZER of the event; an event with no
	 *                ORGANIZER is treated as the organizer's, since that is what the server stores
	 */
	private function isOrganizer(VEvent $event, string $userId): bool {
		if (!isset($event->ORGANIZER)) {
			return true;
		}
		return strtolower((string)$event->ORGANIZER) === 'mailto:' . strtolower($this->attendees->organizer($userId)['email']);
	}

	/**
	 * @param VEvent $master event being changed
	 * @param array{start?: string, end?: string, allDay?: bool, timeZone?: string} $changes requested timing changes
	 * @return EventTiming merged timing
	 * @throws CalendarArgumentException when all-day changes without both dates, or a value is invalid
	 */
	private function mergeTiming(VEvent $master, array $changes): EventTiming {
		$current = $this->builder->timing($master);
		$allDay = $changes['allDay'] ?? $current['allDay'];
		if ($allDay !== $current['allDay'] && (!isset($changes['start']) || !isset($changes['end']))) {
			throw new CalendarArgumentException(CalendarMessages::allDayNeedsDates());
		}
		$parse = fn (string $value, string $label) => $allDay ? $this->dates->day($value, $label) : $this->dates->parse($value, $label);
		$start = isset($changes['start']) ? $parse($changes['start'], 'start') : $current['start'];
		$end = isset($changes['end']) ? $parse($changes['end'], 'end') : $current['end'];
		$zone = $allDay ? null : (isset($changes['timeZone']) ? $this->dates->timeZone($changes['timeZone']) : $current['timeZone']);
		return new EventTiming($start, $end, $allDay, $zone);
	}
}
