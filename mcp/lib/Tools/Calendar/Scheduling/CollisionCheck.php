<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
     * Finds colliding occurrences in the target calendar within [start, end).
     *
     * @param Calendar $calendar target calendar
     * @param string $userId authenticated user id viewing or scheduling
     * @param DateTimeImmutable $start window start instant or civil day
     * @param DateTimeImmutable $end window end instant or civil day
     * @param bool $allDay whether the window represents full civil day(s)
     * @param string|null $excludeUri object URI to exclude (e.g. self when editing or moving)
     * @param int $limit maximum number of colliding items to return
     * @return array{items: list<array{uri:string, summary:?string, start:string, end:string, allDay:bool, busyOnly:bool}>, more:int}
     *   items: up to $limit occurrences overlapping [start, end) in the calendar, ordered by start time;
     *   summary is null when busyOnly; start/end are ISO 8601 UTC; more: count of collisions beyond limit.
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
        // An all-day event has no zone: its days, as the expander reads them, are UTC midnights. Whatever the new event
        // is, an existing all-day event is compared as days: the civil days the new event covers in its own zone, as
        // UTC midnights. Against a timed one the window applies as instants. The store is asked about both at once.
        [$dayStart, $dayEnd] = $allDay ? [$searchStart, $searchEnd] : $this->window($start, $end, true);
        $civil = $this->civilDays($dayStart, $dayEnd);
        $queryStart = min($searchStart, $civil[0]);
        $queryEnd = max($searchEnd, $civil[1]);

        $uris = $this->store->eventUrisInRange($calendar->id, $queryStart, $queryEnd);
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

            foreach ($this->expander->occurrences($vcalendar, $queryStart, $queryEnd) as $occurrence) {
                $event = $occurrence['event'];
                [$from, $to] = $this->isAllDay($event) ? $civil : [$searchStart, $searchEnd];
                if ($occurrence['start'] >= $to || $occurrence['end'] <= $from) {
                    continue;
                }
                // The CLASS belongs to the component the occurrence comes from, as in calendar_list_events.
                $visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $userId);
                if ($visibility === Classification::HIDDEN) {
                    continue;
                }
                $busyOnly = $visibility === Classification::BUSY;
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
     * Resolves the half-open search window, expanding all-day events to midnight-to-midnight in the zone of the start.
     *
     * @param DateTimeImmutable $start window start
     * @param DateTimeImmutable $end window end
     * @param bool $allDay whether to expand to civil day boundaries
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [searchStart, searchEnd)
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

    /**
     * @param DateTimeImmutable $searchStart first midnight of the window, in the zone of the day
     * @param DateTimeImmutable $searchEnd closing midnight of the window, in the zone of the day
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} the same civil days as UTC midnights, how all-day events are read
     */
    private function civilDays(DateTimeImmutable $searchStart, DateTimeImmutable $searchEnd): array {
        $utc = new DateTimeZone('UTC');
        return [new DateTimeImmutable($searchStart->format('Y-m-d'), $utc), new DateTimeImmutable($searchEnd->format('Y-m-d'), $utc)];
    }

    /**
     * Determines whether an event occurrence has transparent time availability (TRANSP:TRANSPARENT).
     *
     * @param VEvent $event occurrence or master event component
     * @param VCalendar $vcalendar parent calendar document to resolve recurrence master transparency
     * @return bool true if the event does not block calendar time
     */
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

    /**
     * Checks if an event is an all-day event without a time component.
     *
     * @param VEvent $event event component
     * @return bool true if DTSTART is a DATE rather than a DATE-TIME
     */
    private function isAllDay(VEvent $event): bool {
        $start = $event->DTSTART ?? null;
        return $start instanceof DateTimeProperty ? !$start->hasTime() : false;
    }

    /**
     * Formats an instant as an ISO 8601 UTC string.
     *
     * @param DateTimeImmutable $date instant to format
     * @return string formatted UTC string (YYYY-MM-DDTHH:MM:SSZ)
     */
    private function formatUtc(DateTimeImmutable $date): string {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
