<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Sabre\VObject\Settings;

/**
 * Expands the events of one calendar object into the occurrences overlapping a window.
 *
 * RRULE, RDATE, EXDATE and RECURRENCE-ID overrides are handled by Sabre's EventIterator;
 * cancelled overrides are dropped. All-day events keep civil days (floating dates read as UTC).
 */
final class EventExpander {
    /** Most occurrences one series may produce inside the window. */
    public const MAX_OCCURRENCES_PER_SERIES = 500;
    /** Most iterator steps per series, counting the ones skipped before the window. */
    public const MAX_ITERATIONS_PER_SERIES = 100000;

    /**
     * @param VCalendar $vcalendar parsed calendar object
     * @param DateTimeImmutable $from inclusive window start
     * @param DateTimeImmutable $to exclusive window end
     * @return list<array{event: VEvent, start: DateTimeImmutable, end: DateTimeImmutable}>
     * @throws CalendarException when a series exceeds the occurrence or iteration limit
     */
    public function occurrences(VCalendar $vcalendar, DateTimeImmutable $from, DateTimeImmutable $to): array {
        $uids = [];
        foreach ($vcalendar->select('VEVENT') as $event) {
            if (isset($event->UID)) {
                $uids[(string)$event->UID] = true;
            }
        }
        $out = [];
        // Our own limits replace Sabre's global cap, which also counts occurrences before the window.
        $previous = Settings::$maxRecurrences;
        Settings::$maxRecurrences = -1;
        try {
            foreach (array_keys($uids) as $uid) {
                array_push($out, ...$this->series($vcalendar, (string)$uid, $from, $to));
            }
        } finally {
            Settings::$maxRecurrences = $previous;
        }
        return $out;
    }

    /**
     * @param VCalendar $vcalendar parsed calendar object
     * @param string $uid series UID
     * @param DateTimeImmutable $from inclusive window start
     * @param DateTimeImmutable $to exclusive window end
     * @return list<array{event: VEvent, start: DateTimeImmutable, end: DateTimeImmutable}>
     * @throws CalendarException when the series exceeds a limit
     */
    private function series(VCalendar $vcalendar, string $uid, DateTimeImmutable $from, DateTimeImmutable $to): array {
        try {
            $iterator = new EventIterator($vcalendar, $uid, new DateTimeZone('UTC'));
        } catch (NoInstancesException) {
            return [];
        }
        $out = [];
        $steps = 0;
        try {
            while ($iterator->valid()) {
                if (++$steps > self::MAX_ITERATIONS_PER_SERIES) {
                    throw CalendarException::limit('Limite de expansão de ocorrências atingido; reduza a janela do calendário.');
                }
                $start = DateTimeImmutable::createFromInterface($iterator->getDtStart());
                if ($start >= $to) {
                    break;
                }
                $end = DateTimeImmutable::createFromInterface($iterator->getDtEnd());
                $event = $iterator->getEventObject();
                if ($end > $from && !$this->cancelledOverride($event)) {
                    if (count($out) >= self::MAX_OCCURRENCES_PER_SERIES) {
                        throw CalendarException::limit(CalendarMessages::OCCURRENCE_LIMIT);
                    }
                    $out[] = ['event' => $event, 'start' => $start, 'end' => $end];
                }
                $iterator->next();
            }
        } catch (MaxInstancesExceededException) {
            throw CalendarException::limit('Limite de expansão de ocorrências atingido; reduza a janela do calendário.');
        }
        return $out;
    }

    /**
     * @param VEvent $event occurrence object returned by the iterator
     * @return bool whether it is an override marked STATUS:CANCELLED
     */
    private function cancelledOverride(VEvent $event): bool {
        return isset($event->{'RECURRENCE-ID'}) && strtoupper((string)($event->STATUS ?? '')) === 'CANCELLED';
    }
}
