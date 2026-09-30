<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Creates VEVENTs and changes their text and timing, leaving every other property untouched.
 */
final class EventBuilder {
    /** PRODID written on objects created by the app. */
    public const PRODID = '-//Nextcloud MCP//Calendar//PT';

    /**
     * @param string $uid new event UID
     * @param array{summary:string, location?:string, description?:string} $text text fields
     * @param EventTiming $timing start, end and zone
     * @param DateTimeImmutable $now creation time
     * @return VCalendar calendar object with one VEVENT
     */
    public function create(string $uid, array $text, EventTiming $timing, DateTimeImmutable $now): VCalendar {
        $vcalendar = new VCalendar(['PRODID' => self::PRODID]);
        $event = $vcalendar->add('VEVENT', ['UID' => $uid]);
        $utcNow = $now->setTimezone(new DateTimeZone('UTC'));
        $event->add('DTSTAMP', $utcNow);
        $event->add('CREATED', $utcNow);
        $event->add('LAST-MODIFIED', $utcNow);
        $event->add('SEQUENCE', 0);
        $this->setTiming($event, $timing);
        $this->setText($event, $text);
        return $vcalendar;
    }

    /**
     * Sets SUMMARY, LOCATION and DESCRIPTION; an empty location or description removes it.
     *
     * @param VEvent $event event to change
     * @param array<string, string> $text any of "summary", "location", "description"
     */
    public function setText(VEvent $event, array $text): void {
        foreach (['summary' => 'SUMMARY', 'location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $key => $property) {
            if (!array_key_exists($key, $text)) {
                continue;
            }
            unset($event->{$property});
            if ($text[$key] !== '') {
                $event->add($property, $text[$key]);
            }
        }
    }

    /**
     * Replaces DTSTART and DTEND (and drops DURATION).
     *
     * @param VEvent $event event to change
     * @param EventTiming $timing new timing
     */
    public function setTiming(VEvent $event, EventTiming $timing): void {
        unset($event->DTSTART, $event->DTEND, $event->DURATION);
        if ($timing->allDay) {
            $event->add('DTSTART', $timing->start, ['VALUE' => 'DATE']);
            $event->add('DTEND', $timing->end, ['VALUE' => 'DATE']);
            return;
        }
        $zone = $timing->timeZone ?? new DateTimeZone('UTC');
        $event->add('DTSTART', $timing->start->setTimezone($zone));
        $event->add('DTEND', $timing->end->setTimezone($zone));
    }

    /**
     * Reads the current timing of an event; a missing DTEND follows RFC 5545 defaults.
     *
     * @param VEvent $event event with DTSTART
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable, allDay: bool, timeZone: DateTimeZone|null} current timing, unvalidated
     */
    public function timing(VEvent $event): array {
        $utc = new DateTimeZone('UTC');
        $allDay = !$event->DTSTART->hasTime();
        $start = DateTimeImmutable::createFromInterface($event->DTSTART->getDateTime($utc));
        if (isset($event->DTEND)) {
            $end = DateTimeImmutable::createFromInterface($event->DTEND->getDateTime($utc));
        } elseif (isset($event->DURATION)) {
            $end = $start->add($event->DURATION->getDateInterval());
        } else {
            $end = $allDay ? $start->add(new DateInterval('P1D')) : $start;
        }
        $tzid = $event->DTSTART['TZID'] ?? null;
        $zone = $allDay || $tzid === null ? null : $this->zoneOrNull((string)$tzid);
        return ['start' => $start->setTimezone($utc), 'end' => $end->setTimezone($utc), 'allDay' => $allDay, 'timeZone' => $zone];
    }

    /**
     * Marks the event as changed: SEQUENCE + 1, DTSTAMP and LAST-MODIFIED = now.
     *
     * @param VEvent $event event to change
     * @param DateTimeImmutable $now change time
     */
    public function touch(VEvent $event, DateTimeImmutable $now): void {
        $utcNow = $now->setTimezone(new DateTimeZone('UTC'));
        $sequence = isset($event->SEQUENCE) ? (int)(string)$event->SEQUENCE : 0;
        unset($event->SEQUENCE, $event->DTSTAMP, $event->{'LAST-MODIFIED'});
        $event->add('SEQUENCE', $sequence + 1);
        $event->add('DTSTAMP', $utcNow);
        $event->add('LAST-MODIFIED', $utcNow);
    }

    /**
     * @param string $tzid TZID parameter value
     * @return DateTimeZone|null PHP zone when the id is a known IANA name
     */
    private function zoneOrNull(string $tzid): ?DateTimeZone {
        return in_array($tzid, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? new DateTimeZone($tzid) : null;
    }
}
