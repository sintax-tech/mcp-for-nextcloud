<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;

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
     * @param VCalendar $vcalendar parsed calendar object
     * @param Calendar $calendar calendar holding it
     * @param string $userId viewing user
     * @return string one of FULL, BUSY or HIDDEN
     */
    public function visibility(VCalendar $vcalendar, Calendar $calendar, string $userId): string {
        if (!$calendar->ownedByOther($userId)) {
            return self::FULL;
        }
        $class = null;
        foreach ($vcalendar->select('VEVENT') as $event) {
            if (isset($event->CLASS) && $event->{'RECURRENCE-ID'} === null) {
                $class = strtoupper((string)$event->CLASS);
            }
        }
        return match ($class) {
            'PRIVATE' => self::HIDDEN,
            'CONFIDENTIAL' => self::BUSY,
            default => self::FULL,
        };
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
