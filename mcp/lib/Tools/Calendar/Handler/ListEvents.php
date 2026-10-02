<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Calendar\EventExpander;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * calendar_list_events: occurrences overlapping a window of at most 366 days, capped at 2 000 items.
 */
final class ListEvents implements CalendarTool {
    /** Most items returned by one call; beyond it the list is cut and flagged. */
    public const MAX_ITEMS = 2000;

    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events iCalendar parsing
     * @param Classification $classification CLASS rules
     * @param EventExpander $expander recurrence expansion
     * @param EventMapper $mapper output mapping
     * @param DateInput $dates date parsing
     * @param ITimeFactory $time clock
     * @param LoggerInterface $logger logger (never receives event content)
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private EventRepository $events,
        private Classification $classification,
        private EventExpander $expander,
        private EventMapper $mapper,
        private DateInput $dates,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition('calendar_list_events', CalendarMessages::TOOL_LIST_EVENTS, 'read', [
            'calendar' => ToolSchema::calendar(CalendarMessages::propertyDescription('calendar-optional')),
            'from' => ToolSchema::date(CalendarMessages::propertyDescription('from')),
            'to' => ToolSchema::date(CalendarMessages::propertyDescription('to')),
        ]);
    }

    /**
     * @param array{calendar?: string, from?: string, to?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} items, plus {truncated, limit} when cut
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the calendar is not visible or a limit is exceeded
     * @throws \OCA\Mcp\Tools\Calendar\CalendarArgumentException on invalid dates
     */
    public function execute(array $arguments, string $userId): array {
        [$from, $to] = $this->dates->window($arguments['from'] ?? null, $arguments['to'] ?? null, $this->time->now());
        $calendars = isset($arguments['calendar'])
            ? [$this->access->resolve($userId, $arguments['calendar'])]
            : $this->access->visible($userId);
        $items = [];
        foreach ($calendars as $calendar) {
            foreach ($this->store->objects($calendar->id, $this->store->eventUrisInRange($calendar->id, $from, $to)) as $row) {
                $vcalendar = $this->events->parse($row['data']);
                if ($vcalendar === null) {
                    $this->logger->warning('MCP calendar: invalid calendar object skipped', ['app' => 'mcp', 'exception_class' => 'Sabre\VObject\ParseException']);
                    continue;
                }
                foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
                    // The CLASS belongs to the component the occurrence comes from: a private override of a public series
                    // is hidden alone, and an override with no CLASS takes the one of its master.
                    $visibility = $this->classification->visibilityOf($occurrence['event'], $vcalendar, $calendar, $userId);
                    if ($visibility === Classification::HIDDEN) {
                        continue;
                    }
                    if (count($items) >= self::MAX_ITEMS) {
                        return ToolSchema::result($items, ['truncated' => true, 'limit' => self::MAX_ITEMS]);
                    }
                    $items[] = $this->mapper->toItem($occurrence['event'], $occurrence['start'], $occurrence['end'], $calendar, $visibility);
                }
            }
        }
        return ToolSchema::result($items);
    }
}
