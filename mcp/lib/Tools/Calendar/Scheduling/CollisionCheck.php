<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\EventExpander;
use OCA\Mcp\Tools\Calendar\EventRepository;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * Finds colliding occurrences in a destination calendar.
 */
final class CollisionCheck {
    /**
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events iCalendar parsing
     * @param Classification $classification CLASS rules
     * @param EventExpander $expander recurrence expansion
     * @param LoggerInterface|null $logger logger (never receives event content)
     */
    public function __construct(
        private CalendarStore $store,
        private EventRepository $events,
        private Classification $classification,
        private EventExpander $expander,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array{items: list<array{uri:string, summary:?string, start:string, end:string, allDay:bool, busyOnly:bool}>, more:int}
     *   items: até $limit ocorrências que cruzam [start, end) no calendário, ordenadas por início; summary null quando busyOnly;
     *   start/end ISO 8601 UTC; more: quantas além do limite.
     */
    public function find(
        Calendar $calendar,
        string $userId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $allDay,
        ?string $excludeUri = null,
        int $limit = 5,
    ): array {
        [$searchStart, $searchEnd] = $this->window($start, $end, $allDay);

        $uris = $this->store->eventUrisInRange($calendar->id, $searchStart, $searchEnd);
        if ($excludeUri !== null) {
            $uris = array_values(array_filter($uris, static fn (string $uri) => $uri !== $excludeUri));
        }

        if ($uris === []) {
            return ['items' => [], 'more' => 0];
        }

        $collisions = [];
        $rows = $this->store->objects($calendar->id, array_values(array_unique($uris)));

        foreach ($rows as $row) {
            if ($excludeUri !== null && $row['uri'] === $excludeUri) {
                continue;
            }

            $vcalendar = $this->events->parse($row['data']);
            if ($vcalendar === null) {
                $this->logger?->warning('MCP calendar: invalid calendar object skipped', [
                    'app' => 'mcp',
                    'exception_class' => 'Sabre\\VObject\\ParseException',
                ]);
                continue;
            }

            $visibility = $this->classification->visibility($vcalendar, $calendar, $userId);
            if ($visibility === Classification::HIDDEN) {
                continue;
            }

            $busyOnly = $visibility === Classification::BUSY;

            foreach ($this->expander->occurrences($vcalendar, $searchStart, $searchEnd) as $occurrence) {
                $event = $occurrence['event'];
                if ($this->isTransparent($event, $vcalendar)) {
                    continue;
                }

                $eventAllDay = $this->isAllDay($event);
                $summary = $busyOnly ? null : (isset($event->SUMMARY) ? (string)$event->SUMMARY : null);

                $collisions[] = [
                    'uri' => $row['uri'],
                    'summary' => $summary,
                    'start' => $this->formatUtc($occurrence['start']),
                    'end' => $this->formatUtc($occurrence['end']),
                    'allDay' => $eventAllDay,
                    'busyOnly' => $busyOnly,
                    '_sortStart' => $occurrence['start'],
                    '_sortEnd' => $occurrence['end'],
                ];
            }
        }

        usort($collisions, static function (array $a, array $b): int {
            $cmp = $a['_sortStart'] <=> $b['_sortStart'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = $a['_sortEnd'] <=> $b['_sortEnd'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['uri'] <=> $b['uri'];
        });

        $total = count($collisions);
        $slice = array_slice($collisions, 0, max(0, $limit));

        $items = [];
        foreach ($slice as $collision) {
            unset($collision['_sortStart'], $collision['_sortEnd']);
            $items[] = $collision;
        }

        return [
            'items' => $items,
            'more' => max(0, $total - count($items)),
        ];
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function window(DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay): array {
        if (!$allDay) {
            return [$start, $end];
        }

        $tz = $start->getTimezone();
        $searchStart = $start->setTime(0, 0, 0);
        $endInTz = $end->setTimezone($tz);

        if ($endInTz > $start && $endInTz->format('H:i:s') === '00:00:00') {
            $searchEnd = $endInTz;
        } else {
            $searchEnd = $endInTz->setTime(0, 0, 0)->modify('+1 day');
        }

        if ($searchEnd <= $searchStart) {
            $searchEnd = $searchStart->modify('+1 day');
        }

        return [$searchStart, $searchEnd];
    }

    private function isTransparent(VEvent $event, VCalendar $vcalendar): bool {
        if (isset($event->TRANSP)) {
            return strtoupper(trim((string)$event->TRANSP)) === 'TRANSPARENT';
        }

        if ($event->{'RECURRENCE-ID'} !== null) {
            $uid = (string)($event->UID ?? '');
            foreach ($vcalendar->select('VEVENT') as $candidate) {
                if ($candidate->{'RECURRENCE-ID'} === null && (string)($candidate->UID ?? '') === $uid && isset($candidate->TRANSP)) {
                    return strtoupper(trim((string)$candidate->TRANSP)) === 'TRANSPARENT';
                }
            }
        }

        return false;
    }

    private function isAllDay(VEvent $event): bool {
        $start = $event->DTSTART ?? null;
        return $start instanceof DateTimeProperty ? !$start->hasTime() : false;
    }

    private function formatUtc(DateTimeImmutable $date): string {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
