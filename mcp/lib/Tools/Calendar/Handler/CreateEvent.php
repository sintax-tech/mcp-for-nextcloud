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
use OCA\Mcp\Tools\Calendar\Scheduling;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * calendar_create_event: creates a single, non-recurring event in a writable calendar.
 *
 * The write itself goes through the CalDAV pipeline (CalendarDav), so the event lands exactly as it
 * would if the user had created it in the Calendar app: same ACL, same validation, same trash, same
 * sync. Only the read-back goes through the store.
 */
final class CreateEvent implements CalendarTool {
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
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string, destructiveHint:bool}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_create_event',
            CalendarMessages::TOOL_CREATE_EVENT
            . CalendarMessages::INVITES_OTHERS . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
            'create',
            [
                'calendar' => ToolSchema::calendar(),
                'summary' => ToolSchema::text('título do evento', 1, 255),
                'start' => ToolSchema::date('início: AAAA-MM-DD (dia inteiro) ou ISO com Z/offset'),
                'end' => ToolSchema::date('fim exclusivo, no mesmo formato de start'),
                'allDay' => ['type' => 'boolean', 'default' => false, 'description' => 'evento de dia inteiro'],
                'timeZone' => ToolSchema::text('fuso IANA, ex.: America/Sao_Paulo', 1, 64),
                'location' => ToolSchema::text('local', 0, 255),
                'description' => ToolSchema::text('descrição', 0, 65536),
                'attendees' => ToolSchema::attendees(),
                'send_invitations' => ToolSchema::sendInvitations(),
                'confirm_shared' => SharedGuard::property(),
            ],
            ['calendar', 'summary', 'start', 'end'],
        ) + ['destructiveHint' => true];
    }

    /**
     * @param array{calendar: string, summary: string, start: string, end: string, allDay?: bool, timeZone?: string, location?: string, description?: string, attendees?: list<string>, send_invitations?: bool, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} created event item plus etag and scheduling
     * @throws CalendarException when the calendar is not visible, not writable, the UID is taken or the pipeline refuses
     * @throws CalendarArgumentException on invalid dates, time zone or guest list
     */
    public function execute(array $arguments, string $userId): array {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
            return $confirmation;
        }
        $timing = $this->dates->timing($arguments['start'], $arguments['end'], (bool)($arguments['allDay'] ?? false), $arguments['timeZone'] ?? null);
        $guests = $this->attendees->resolve($arguments['attendees'] ?? [], $userId);
        $organizer = $guests === [] ? null : $this->attendees->organizer($userId);
        $uid = $this->newUid();
        $uri = $uid . '.ics';
        $this->events->assertFree($calendar, $uid, $uri);
        $text = array_intersect_key($arguments, ['summary' => true, 'location' => true, 'description' => true]);
        $vcalendar = $this->builder->create($uid, $text, $timing, $this->time->now());
        if ($organizer !== null) {
            $this->builder->setAttendees($vcalendar->VEVENT, $guests, $organizer['email'], $organizer['displayName']);
        }
        $notify = (bool)($arguments['send_invitations'] ?? false);
        $this->dav->put($userId, $calendar->uri, $uri, $vcalendar->serialize(), $notify);
        // The ETag comes from the re-read: Sabre omits it in the response once a plugin has
        // rewritten the data (sabre/dav/lib/DAV/CorePlugin.php:428-518).
        $row = $this->store->object($calendar->id, $uri)
            ?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        $written = $this->events->parse($row['data']) ?? throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
        $item = $this->mapper->toItem($written->VEVENT, $timing->start, $timing->end, $calendar, Classification::FULL);
        return ToolSchema::result($item + [
            'etag' => $row['etag'],
            'scheduling' => $this->scheduling->report($notify, $written),
        ]);
    }

    /**
     * @return string random RFC 4122 version 4 UUID
     */
    private function newUid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}