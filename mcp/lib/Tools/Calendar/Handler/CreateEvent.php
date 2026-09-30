<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Calendar\EventBuilder;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * calendar_create_event: creates a single, non-recurring event in a writable calendar.
 */
final class CreateEvent implements CalendarTool {
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events UID/URI conflict checks
     * @param EventBuilder $builder VEVENT construction
     * @param EventMapper $mapper output mapping
     * @param DateInput $dates date parsing
     * @param ITimeFactory $time clock
     */
    public function __construct(
        private CalendarAccess $access,
        private SharedGuard $guard,
        private CalendarStore $store,
        private EventRepository $events,
        private EventBuilder $builder,
        private EventMapper $mapper,
        private DateInput $dates,
        private ITimeFactory $time,
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_create_event',
            'Cria um evento simples (sem recorrência) num calendário com permissão de escrita.' . ToolSchema::NO_NOTIFICATION . SharedGuard::DESCRIPTION_SUFFIX,
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
                'confirm_shared' => SharedGuard::property(),
            ],
            ['calendar', 'summary', 'start', 'end'],
        );
    }

    /**
     * @param array{calendar: string, summary: string, start: string, end: string, allDay?: bool, timeZone?: string, location?: string, description?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} created event item plus etag
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the calendar is not visible, not writable or the UID is taken
     * @throws \OCA\Mcp\Tools\Calendar\CalendarArgumentException on invalid dates or time zone
     */
    public function execute(array $arguments, string $userId): array {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
            return $confirmation;
        }
        $timing = $this->dates->timing($arguments['start'], $arguments['end'], (bool)($arguments['allDay'] ?? false), $arguments['timeZone'] ?? null);
        $uid = $this->newUid();
        $uri = $uid . '.ics';
        $this->events->assertFree($calendar, $uid, $uri);
        $text = array_intersect_key($arguments, ['summary' => true, 'location' => true, 'description' => true]);
        $vcalendar = $this->builder->create($uid, $text, $timing, $this->time->now());
        $etag = $this->store->create($calendar->id, $uri, $vcalendar->serialize());
        $item = $this->mapper->toItem($vcalendar->VEVENT, $timing->start, $timing->end, $calendar, Classification::FULL);
        return ToolSchema::result($item + ['etag' => $etag]);
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
