<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validated start, end, all-day flag and optional time zone of an event.
 */
final class EventTiming {
    /**
     * @param DateTimeImmutable $start instant in UTC, or the civil day at UTC midnight when all-day
     * @param DateTimeImmutable $end exclusive end, same convention as start
     * @param bool $allDay whether dates are civil days
     * @param DateTimeZone|null $timeZone zone to write DTSTART/DTEND in; null writes UTC (ignored when all-day)
     * @throws CalendarArgumentException when end is not after start
     */
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly bool $allDay,
        public readonly ?DateTimeZone $timeZone,
    ) {
        if ($end <= $start) {
            throw new CalendarArgumentException(CalendarMessages::rangeEndBeforeStart());
        }
    }
}
