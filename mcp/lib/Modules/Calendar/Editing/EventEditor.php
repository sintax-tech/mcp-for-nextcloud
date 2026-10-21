<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Editing;

use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates as DateInput;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Events\EventTiming;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Component\VEvent;
/**
 * calendar_update_event: changes text and, for non-recurring events, timing of the master VEVENT.
 *
 * The write itself goes through the CalDAV pipeline (CalendarDav) with If-Match always set to the
 * ETag that was just read, so a concurrent edit by somebody else loses instead of overwriting.
 */
final class EventEditor implements \OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation
{
    /** Arguments that change the event text. */
    private const TEXT_FIELDS = ['summary', 'location', 'description'];
    /** Arguments that change the event timing. */
    private const TIMING_FIELDS = [
        'start',
        'end',
        'allDay',
        'timeZone',
    ];
    /**
     * @param CalendarAccess $access calendar visibility and ACL
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
        private CalendarAccess $access,
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
    )
    {
    }
    /**
     * @param array{calendar: string, uid: string, summary?: string, start?: string, end?: string, allDay?: bool, timeZone?: string, location?: string, description?: string, attendees?: list<string>, send_invitations?: bool, etag?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} updated event item plus etag and scheduling
     * @throws CalendarException when not visible, not writable, changed meanwhile or a recurring timing change
     * @throws CalendarArgumentException when nothing is given, dates are invalid or the guest list is refused
     */
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : $prepared->dispatch();
    }
    /**
     * Validates and constructs the write without dispatching, so the registry can show its plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array
    {
        $timingChanges = array_intersect_key($arguments, array_flip(self::TIMING_FIELDS));
        $textChanges = array_intersect_key($arguments, array_flip(self::TEXT_FIELDS));
        $guestChanges = array_key_exists('attendees', $arguments);
        if ($timingChanges === [] && $textChanges === [] && !$guestChanges) {
            throw new CalendarArgumentException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::noFieldGiven());
        }
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = ($arguments['confirm_shared'] ?? false) === true ? null : $this->guard->forCollection($calendar, $userId)) !== null) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($confirmation);
        }
        $stored = $this->events->forChange(
            $calendar,
            $arguments['uid'],
            $userId,
            $arguments['etag'] ?? null
        );
        $before = new \OCA\Mcp\Modules\Calendar\Events\StoredEvent(
            $stored->id,
            $stored->uri,
            $stored->etag,
            clone $stored->vcalendar
        );
        $master = $stored->master() ?? throw CalendarException::notFound();
        if ($timingChanges !== []) {
            if ($stored->recurring()) {
                throw CalendarException::conflict(\OCA\Mcp\Modules\Calendar\Events\EventMessages::recurringTiming());
            }
            $this->builder->setTiming($master, $this->mergeTiming($master, $timingChanges));
        }
        $this->builder->setText($master, $textChanges);
        if ($guestChanges) {
            $this->replaceGuests(
                $master,
                $stored,
                $arguments['attendees'] ?? [],
                $userId
            );
        }
        $this->builder->touch($master, $this->time->now());
        $notify = (bool) ($arguments['send_invitations'] ?? false);
        // If-Match always carries the ETag just read, even when the client sent none, so a change
        // made between the read and the write loses instead of being overwritten (an ETag mismatch aborts the update).
        return new \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite(
            $calendar,
            null,
            $before,
            $stored->vcalendar,
            function () use ($calendar, $stored, $notify, $userId): array {
                $this->dav->update(
                    $userId,
                    $calendar->uri,
                    $stored->uri,
                    $stored->etag,
                    $stored->vcalendar->serialize(),
                    $notify
                );
                $row = $this->store->object($calendar->id, $stored->uri) ?? throw new \RuntimeException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
                $written = $this->events->parse($row['data']) ?? throw new \RuntimeException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
                $timing = $this->builder->timing($written->VEVENT);
                $item = $this->mapper->toItem(
                    $written->VEVENT,
                    $timing['start'],
                    $timing['end'],
                    $calendar,
                    Classification::FULL
                );
                return ToolSchema::json(
                    $item + [
                        'etag' => $row['etag'],
                        'scheduling' => $this->scheduling->report($notify, $written),
                    ]
                );
            }
        );
    }
    /**
     * Replaces the guest list of a series or of somebody else's event only when the caller may.
     *
     * @param VEvent $master event being changed
     * @param \OCA\Mcp\Modules\Calendar\Events\StoredEvent $stored event as read from the store
     * @param list<string> $uids internal account ids sent by the caller; an empty list removes every guest
     * @param string $userId acting user
     * @return void
     * @throws CalendarArgumentException when a UID cannot be invited
     * @throws CalendarException when the guest list may not be changed here
     */
    private function replaceGuests(
        VEvent $master,
        \OCA\Mcp\Modules\Calendar\Events\StoredEvent $stored,
        array $uids,
        string $userId,
    ): void
    {
        if ($stored->recurring()) {
            throw CalendarException::blocked(\OCA\Mcp\Modules\Calendar\Events\EventMessages::recurringAttendees());
        }
        if (!$this->isOrganizer($master, $userId)) {
            throw CalendarException::blocked(\OCA\Mcp\Modules\Calendar\Events\EventMessages::notOrganizer());
        }
        if ($uids === []) {
            unset($master->ATTENDEE);
            return;
        }
        $guests = $this->attendees->resolve($uids, $userId);
        $this->builder->setAttendees(
            $master,
            $guests,
            $this->attendees->organizer($userId)['email'],
            $this->attendees->organizer($userId)['displayName']
        );
    }
    /**
     * @param VEvent $event event to inspect
     * @param string $userId acting user
     * @return bool whether the acting account is the ORGANIZER of the event; an event with no
     *                ORGANIZER is treated as the organizer's, since that is what the server stores
     */
    private function isOrganizer(VEvent $event, string $userId): bool
    {
        if (!isset($event->ORGANIZER)) {
            return true;
        }
        return strtolower((string) $event->ORGANIZER) === 'mailto:' . strtolower($this->attendees->organizer($userId)['email']);
    }
    /**
     * @param VEvent $master event being changed
     * @param array{start?: string, end?: string, allDay?: bool, timeZone?: string} $changes requested timing changes
     * @return EventTiming merged timing
     * @throws CalendarArgumentException when all-day changes without both dates, or a value is invalid
     */
    private function mergeTiming(VEvent $master, array $changes): EventTiming
    {
        $current = $this->builder->timing($master);
        $allDay = $changes['allDay'] ?? $current['allDay'];
        if ($allDay !== $current['allDay'] && (!isset($changes['start']) || !isset($changes['end']))) {
            throw new CalendarArgumentException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::allDayNeedsDates());
        }
        $parse = fn(string $value, string $label) => $allDay ? $this->dates->day($value, $label) : $this->dates->parse($value, $label);
        $start = isset($changes['start']) ? $parse($changes['start'], 'start') : $current['start'];
        $end = isset($changes['end']) ? $parse($changes['end'], 'end') : $current['end'];
        $zone = $allDay ? null : (isset($changes['timeZone']) ? $this->dates->timeZone($changes['timeZone']) : $current['timeZone']);
        return new EventTiming(
            $start,
            $end,
            $allDay,
            $zone
        );
    }
}
