<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Applies iCalendar CLASS rules to calendars owned by someone else, as the Sabre CalDAV plugin
 * does and direct backend access would otherwise skip.
 */
final class Classification {
    /** The event is shown in full. */
    public const FULL = 'full';
    /** Only the time is shown; summary becomes "Ocupado". */
    public const BUSY = 'busy';
    /** The event is not shown at all. */
    public const HIDDEN = 'hidden';

    /**
     * Visibility of one component of the object. The master and every override carry a CLASS of their own; an override
     * with none takes the one of the master, and a master with none is public.
     *
     * @param VEvent $event component being read: the master, an override, or an occurrence taken from either
     * @param VCalendar $vcalendar parsed calendar object the component belongs to
     * @param Calendar $calendar calendar holding it
     * @param string $userId viewing user
     * @return string one of FULL, BUSY or HIDDEN
     */
    public function visibilityOf(VEvent $event, VCalendar $vcalendar, Calendar $calendar, string $userId): string {
        if (!$calendar->ownedByOther($userId)) {
            return self::FULL;
        }
        $class = $this->classOf($event);
        if ($class === null && $event->{'RECURRENCE-ID'} !== null) {
            $class = $this->classOf($this->masterOf($event, $vcalendar));
        }
        return match ($class) {
            'PRIVATE' => self::HIDDEN,
            'CONFIDENTIAL' => self::BUSY,
            default => self::FULL,
        };
    }

    /**
     * Visibility of the object as a whole: the most restrictive of its components. It decides whether the object may be
     * changed or linked; what a reader sees of each occurrence is {@see self::visibilityOf()}.
     *
     * @param VCalendar $vcalendar parsed calendar object
     * @param Calendar $calendar calendar holding it
     * @param string $userId viewing user
     * @return string one of FULL, BUSY or HIDDEN
     */
    public function visibility(VCalendar $vcalendar, Calendar $calendar, string $userId): string {
        $overall = self::FULL;
        foreach ($vcalendar->select('VEVENT') as $event) {
            $visibility = $this->visibilityOf($event, $vcalendar, $calendar, $userId);
            if ($visibility === self::HIDDEN) {
                return self::HIDDEN;
            }
            if ($visibility === self::BUSY) {
                $overall = self::BUSY;
            }
        }
        return $overall;
    }

    /**
     * @param VEvent|null $event component to read
     * @return string|null its CLASS in upper case (iCalendar values do not depend on case), or null when it has none
     */
    private function classOf(?VEvent $event): ?string {
        if ($event === null || !isset($event->CLASS)) {
            return null;
        }
        $class = strtoupper(trim((string)$event->CLASS));
        return $class === '' ? null : $class;
    }

    /**
     * @param VEvent $override component with a RECURRENCE-ID
     * @param VCalendar $vcalendar object it belongs to
     * @return VEvent|null the master of the same UID, or null when the object has none
     */
    private function masterOf(VEvent $override, VCalendar $vcalendar): ?VEvent {
        $uid = (string)($override->UID ?? '');
        foreach ($vcalendar->select('VEVENT') as $candidate) {
            if ($candidate->{'RECURRENCE-ID'} === null && (string)($candidate->UID ?? '') === $uid) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Requires the object to be fully visible before any change.
     *
     * @param VCalendar $vcalendar parsed calendar object
     * @param Calendar $calendar calendar holding it
     * @param string $userId acting user
     * @throws CalendarException not found for PRIVATE, forbidden for CONFIDENTIAL
     */
    public function assertModifiable(VCalendar $vcalendar, Calendar $calendar, string $userId): void {
        $visibility = $this->visibility($vcalendar, $calendar, $userId);
        if ($visibility === self::HIDDEN) {
            throw CalendarException::notFound();
        }
        if ($visibility === self::BUSY) {
            throw CalendarException::forbidden();
        }
    }
}
