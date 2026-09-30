<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * Converts one event occurrence into the tool output item of the Node prototype.
 */
final class EventMapper {
    /** Summary shown for CONFIDENTIAL events of other owners. */
    public const BUSY_SUMMARY = 'Ocupado';

    /**
     * @param VEvent $event occurrence (master or override)
     * @param DateTimeImmutable $start occurrence start
     * @param DateTimeImmutable $end occurrence end (exclusive)
     * @param Calendar $calendar calendar holding the event
     * @param string $visibility Classification::FULL or Classification::BUSY
     * @return array{uid:string, summary:string, start:string, end:string, location:string, allDay:bool, timeZone?:string, calendar:string}
     */
    public function toItem(VEvent $event, DateTimeImmutable $start, DateTimeImmutable $end, Calendar $calendar, string $visibility): array {
        $allDay = $this->isAllDay($event);
        $busy = $visibility === Classification::BUSY;
        $item = [
            'uid' => (string)($event->UID ?? ''),
            'summary' => $busy ? self::BUSY_SUMMARY : (string)($event->SUMMARY ?? ''),
            'start' => $this->format($start, $allDay),
            'end' => $this->format($end, $allDay),
            'location' => $busy ? '' : (string)($event->LOCATION ?? ''),
            'allDay' => $allDay,
        ];
        $timeZone = $allDay ? null : $this->timeZone($event);
        if ($timeZone !== null) {
            $item['timeZone'] = $timeZone;
        }
        $item['calendar'] = $calendar->path;
        return $item;
    }

    /**
     * @param VEvent $event event with DTSTART
     * @return bool whether DTSTART is a DATE value
     */
    public function isAllDay(VEvent $event): bool {
        $start = $event->DTSTART;
        return $start instanceof DateTimeProperty && !$start->hasTime();
    }

    /**
     * @param VEvent $event event with DTSTART
     * @return string|null TZID of DTSTART, when present
     */
    public function timeZone(VEvent $event): ?string {
        $tzid = $event->DTSTART['TZID'] ?? null;
        return $tzid === null ? null : (string)$tzid;
    }

    /**
     * @param DateTimeImmutable $date instant or civil day
     * @param bool $allDay whether to print only the civil day
     * @return string "YYYY-MM-DD" or UTC ISO with milliseconds, like JavaScript toISOString()
     */
    private function format(DateTimeImmutable $date, bool $allDay): string {
        return $allDay ? $date->format('Y-m-d') : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
