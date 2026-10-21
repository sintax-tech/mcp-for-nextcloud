<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Creation;

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
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
/**
 * calendar_create_event: creates a single, non-recurring event in a writable calendar.
 *
 * The write itself goes through the CalDAV pipeline (CalendarDav), so the event lands exactly as it
 * would if the user had created it in the Calendar app: same ACL, same validation, same trash, same
 * sync. Only the read-back goes through the store.
 */
final class EventCreator implements \OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation
{
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     * @param CalendarDav $dav the official write pipeline
     * @param CalendarStore $store calendar storage port, used to read the event back
     * @param EventRepository $events UID/URI conflict checks
     * @param EventBuilder $builder VEVENT construction
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
     * @param array{calendar: string, summary: string, start: string, end: string, allDay?: bool, timeZone?: string, location?: string, description?: string, attendees?: list<string>, send_invitations?: bool, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} created event item plus etag and scheduling
     * @throws CalendarException when the calendar is not visible, not writable, the UID is taken or the pipeline refuses
     * @throws CalendarArgumentException on invalid dates, time zone or guest list
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
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = ($arguments['confirm_shared'] ?? false) === true ? null : $this->guard->forCollection($calendar, $userId)) !== null) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($confirmation);
        }
        $timing = $this->dates->timing(
            $arguments['start'],
            $arguments['end'],
            (bool) ($arguments['allDay'] ?? false),
            $arguments['timeZone'] ?? null
        );
        $guests = $this->attendees->resolve($arguments['attendees'] ?? [], $userId);
        $organizer = $guests === [] ? null : $this->attendees->organizer($userId);
        $uid = $this->newUid();
        $uri = $uid . '.ics';
        $this->events->assertFree($calendar, $uid, $uri);
        $text = array_intersect_key($arguments, ['summary' => true, 'location' => true, 'description' => true]);
        $vcalendar = $this->builder->create(
            $uid,
            $text,
            $timing,
            $this->time->now()
        );
        if ($organizer !== null) {
            $this->builder->setAttendees(
                $vcalendar->VEVENT,
                $guests,
                $organizer['email'],
                $organizer['displayName']
            );
        }
        $notify = (bool) ($arguments['send_invitations'] ?? false);
        return new \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite(
            $calendar,
            null,
            null,
            $vcalendar,
            function () use ($calendar, $uid, $uri, $vcalendar, $notify, $userId, $timing): array {
                $this->dav->put(
                    $userId,
                    $calendar->uri,
                    $uri,
                    $vcalendar->serialize(),
                    $notify
                );
                // The ETag comes from the re-read: Sabre omits it in the response once a plugin has
                // rewritten the data (sabre/dav/lib/DAV/CorePlugin.php:428-518).
                $row = $this->store->object($calendar->id, $uri) ?? throw new \RuntimeException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
                $written = $this->events->parse($row['data']) ?? throw new \RuntimeException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
                $item = $this->mapper->toItem(
                    $written->VEVENT,
                    $timing->start,
                    $timing->end,
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
     * @return string random RFC 4122 version 4 UUID
     */
    private function newUid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0xf | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
