<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates as DateInput;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
/**
 * calendar_list_events: occurrences overlapping a window of at most 366 days, capped at 2 000 items.
 */
final class EventReader
{
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
        private \OCA\Mcp\Modules\Calendar\Reading\EventRangeQuery $ranges,
        private EventRepository $events,
        private Classification $classification,
        private EventExpander $expander,
        private EventMapper $mapper,
        private DateInput $dates,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    )
    {
    }
    /**
     * @param array{calendar?: string, from?: string, to?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} items, plus {truncated, limit} when cut
     * @throws \OCA\Mcp\Modules\Calendar\Execution\CalendarException when the calendar is not visible or a limit is exceeded
     * @throws \OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException on invalid dates
     */
    public function execute(array $arguments, string $userId): array
    {
        [$from, $to] = $this->dates->window($arguments['from'] ?? null, $arguments['to'] ?? null, $this->time->now());
        $calendars = isset($arguments['calendar']) ? [$this->access->resolve($userId, $arguments['calendar'])] : $this->access->visible($userId);
        $items = [];
        foreach ($calendars as $calendar) {
            foreach ($this->store->objects($calendar->id, $this->ranges->eventUrisInRange($calendar->id, $from, $to)) as $row) {
                $vcalendar = $this->events->parse($row['data']);
                if ($vcalendar === null) {
                    $this->logger->warning('MCP calendar: invalid calendar object skipped', ['app' => 'mcp', 'exception_class' => 'Sabre\VObject\ParseException']);
                    continue;
                }
                foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
                    // The CLASS belongs to the component the occurrence comes from: a private override of a public series
                    // is hidden alone, and an override with no CLASS takes the one of its master.
                    $visibility = $this->classification->visibilityOf(
                        $occurrence['event'],
                        $vcalendar,
                        $calendar,
                        $userId
                    );
                    if ($visibility === Classification::HIDDEN) {
                        continue;
                    }
                    if (count($items) >= self::MAX_ITEMS) {
                        return ToolSchema::json($items, ['truncated' => true, 'limit' => self::MAX_ITEMS]);
                    }
                    $items[] = $this->mapper->toItem(
                        $occurrence['event'],
                        $occurrence['start'],
                        $occurrence['end'],
                        $calendar,
                        $visibility
                    );
                }
            }
        }
        return ToolSchema::json($items);
    }
}
