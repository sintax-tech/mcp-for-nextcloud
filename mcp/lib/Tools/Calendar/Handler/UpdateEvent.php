<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\AttendeeResolver;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Calendar\EventBuilder;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\EventTiming;
use OCA\Mcp\Tools\Calendar\Scheduling;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Component\VEvent;

/**
 * calendar_update_event: changes text and, for non-recurring events, timing of the master VEVENT.
 *
 * The write itself goes through the CalDAV pipeline (CalendarDav) with If-Match always set to the
 * ETag that was just read, so a concurrent edit by somebody else loses instead of overwriting.
 */
final class UpdateEvent implements \OCA\Mcp\Tools\Calendar\CalendarWriteTool {
    /** Arguments that change the event text. */
    private const TEXT_FIELDS = ['summary', 'location', 'description'];
    /** Arguments that change the event timing. */
    private const TIMING_FIELDS = ['start', 'end', 'allDay', 'timeZone'];

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
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string, destructiveHint:bool}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_update_event',
            CalendarMessages::TOOL_UPDATE_EVENT
            . CalendarMessages::INVITES_OTHERS . CalendarMessages::SEND_INVITATIONS_NOTE . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
            'edit',
            [
                'calendar' => ToolSchema::calendar(),
                'uid' => ToolSchema::uid(),
                'summary' => ToolSchema::text(CalendarMessages::propertyDescription('updated-summary'), 1, 255),
                'start' => ToolSchema::date(CalendarMessages::propertyDescription('updated-start')),
                'end' => ToolSchema::date(CalendarMessages::propertyDescription('updated-end')),
                'allDay' => ['type' => 'boolean', 'description' => CalendarMessages::PROP_ALL_DAY],
                'timeZone' => ToolSchema::text(CalendarMessages::propertyDescription('timezone'), 1, 64),
                'location' => ToolSchema::text(CalendarMessages::propertyDescription('updated-location'), 0, 255),
                'description' => ToolSchema::text(CalendarMessages::propertyDescription('updated-description'), 0, 65536),
                'attendees' => ToolSchema::attendees(true),
                'send_invitations' => ToolSchema::sendInvitations(),
                'etag' => ToolSchema::etag(),
                'confirm_shared' => SharedGuard::property(),
            ],
            ['calendar', 'uid'],
        ) + ['destructiveHint' => true];
    }

    /**
     * @param array{calendar: string, uid: string, summary?: string, start?: string, end?: string, allDay?: bool, timeZone?: string, location?: string, description?: string, attendees?: list<string>, send_invitations?: bool, etag?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} updated event item plus etag and scheduling
     * @throws CalendarException when not visible, not writable, changed meanwhile or a recurring timing change
     * @throws CalendarArgumentException when nothing is given, dates are invalid or the guest list is refused
     */
    public function execute(array $arguments, string $userId): array {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : $prepared->dispatch();
    }

    /** Validate and construct the write without dispatching; direct callers retain SharedGuard. */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite|array {
        $timingChanges = array_intersect_key($arguments, array_flip(self::TIMING_FIELDS));
        $textChanges = array_intersect_key($arguments, array_flip(self::TEXT_FIELDS));
        $guestChanges = array_key_exists('attendees', $arguments);
        if ($timingChanges === [] && $textChanges === [] && !$guestChanges) {
            throw new CalendarArgumentException(CalendarMessages::noFieldGiven());
        }
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
            return $confirmation;
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        $before = new \OCA\Mcp\Tools\Calendar\StoredEvent($stored->id, $stored->uri, $stored->etag, clone $stored->vcalendar);
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
        $this->builder->touch($master, $this->time->now());
        $notify = (bool)($arguments['send_invitations'] ?? false);
        // If-Match always carries the ETag just read, even when the client sent none, so a change
        // made between the read and the write loses instead of being overwritten (D3, seção 2).
        return new \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite($calendar, null, $before, $stored->vcalendar, function () use ($calendar, $stored, $notify, $userId): array {
            $this->dav->update($userId, $calendar->uri, $stored->uri, $stored->etag, $stored->vcalendar->serialize(), $notify);
            $row = $this->store->object($calendar->id, $stored->uri)
                ?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
            $written = $this->events->parse($row['data']) ?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
            $timing = $this->builder->timing($written->VEVENT);
            $item = $this->mapper->toItem($written->VEVENT, $timing['start'], $timing['end'], $calendar, Classification::FULL);
            return ToolSchema::result($item + [
                'etag' => $row['etag'],
                'scheduling' => $this->scheduling->report($notify, $written),
            ]);
        });
    }

    /**
     * Replaces the guest list of a series or of somebody else's event only when the caller may.
     *
     * @param VEvent $master event being changed
     * @param \OCA\Mcp\Tools\Calendar\StoredEvent $stored event as read from the store
     * @param list<string> $uids internal account ids sent by the caller; an empty list removes every guest
     * @param string $userId acting user
     * @return void
     * @throws CalendarArgumentException when a UID cannot be invited
     * @throws CalendarException when the guest list may not be changed here
     */
    private function replaceGuests(VEvent $master, \OCA\Mcp\Tools\Calendar\StoredEvent $stored, array $uids, string $userId): void {
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