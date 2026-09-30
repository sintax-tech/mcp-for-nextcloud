<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Calendar\EventBuilder;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\EventTiming;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Component\VEvent;

/**
 * calendar_update_event: changes text and, for non-recurring events, timing of the master VEVENT.
 */
final class UpdateEvent implements CalendarTool {
    /** Arguments that change the event text. */
    private const TEXT_FIELDS = ['summary', 'location', 'description'];
    /** Arguments that change the event timing. */
    private const TIMING_FIELDS = ['start', 'end', 'allDay', 'timeZone'];

    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events event lookup with classification and ETag checks
     * @param EventBuilder $builder VEVENT changes
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
            'calendar_update_event',
            'Altera título, local, descrição ou datas de um evento. Datas de série recorrente não podem ser alteradas.' . ToolSchema::NO_NOTIFICATION . SharedGuard::DESCRIPTION_SUFFIX,
            'edit',
            [
                'calendar' => ToolSchema::calendar(),
                'uid' => ToolSchema::uid(),
                'summary' => ToolSchema::text('novo título', 1, 255),
                'start' => ToolSchema::date('novo início: AAAA-MM-DD (dia inteiro) ou ISO com Z/offset'),
                'end' => ToolSchema::date('novo fim exclusivo'),
                'allDay' => ['type' => 'boolean', 'description' => 'evento de dia inteiro'],
                'timeZone' => ToolSchema::text('fuso IANA, ex.: America/Sao_Paulo', 1, 64),
                'location' => ToolSchema::text('novo local; vazio remove', 0, 255),
                'description' => ToolSchema::text('nova descrição; vazio remove', 0, 65536),
                'etag' => ToolSchema::etag(),
                'confirm_shared' => SharedGuard::property(),
            ],
            ['calendar', 'uid'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, summary?: string, start?: string, end?: string, allDay?: bool, timeZone?: string, location?: string, description?: string, etag?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} updated event item plus etag
     * @throws CalendarException when not visible, not writable, changed meanwhile or a recurring timing change
     * @throws CalendarArgumentException when no field is given or dates are invalid
     */
    public function execute(array $arguments, string $userId): array {
        $timingChanges = array_intersect_key($arguments, array_flip(self::TIMING_FIELDS));
        $textChanges = array_intersect_key($arguments, array_flip(self::TEXT_FIELDS));
        if ($timingChanges === [] && $textChanges === []) {
            throw new CalendarArgumentException('Informe ao menos um campo para alterar.');
        }
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
            return $confirmation;
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        $master = $stored->master() ?? throw CalendarException::notFound();
        if ($timingChanges !== []) {
            if ($stored->recurring()) {
                throw CalendarException::conflict('alterar datas de uma série recorrente não é suportado.');
            }
            $this->builder->setTiming($master, $this->mergeTiming($master, $timingChanges));
        }
        $this->builder->setText($master, $textChanges);
        $this->builder->touch($master, $this->time->now());
        $etag = $this->store->update($calendar->id, $stored->uri, $stored->vcalendar->serialize());
        $timing = $this->builder->timing($master);
        $item = $this->mapper->toItem($master, $timing['start'], $timing['end'], $calendar, Classification::FULL);
        return ToolSchema::result($item + ['etag' => $etag]);
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
            throw new CalendarArgumentException('Informe start e end ao alterar allDay.');
        }
        $parse = fn (string $value, string $label) => $allDay ? $this->dates->day($value, $label) : $this->dates->parse($value, $label);
        $start = isset($changes['start']) ? $parse($changes['start'], 'start') : $current['start'];
        $end = isset($changes['end']) ? $parse($changes['end'], 'end') : $current['end'];
        $zone = $allDay ? null : (isset($changes['timeZone']) ? $this->dates->timeZone($changes['timeZone']) : $current['timeZone']);
        return new EventTiming($start, $end, $allDay, $zone);
    }
}
