<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Events;

use OCA\Mcp\Modules\Calendar\Events\EventTiming;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
/**
 * Parses and validates the ISO dates, windows and time zones accepted by the calendar tools,
 * using the formats accepted by the calendar tools.
 */
final class EventDates
{
    /** Largest listing window, in seconds (366 days). */
    public const MAX_WINDOW_SECONDS = 366 * 86400;
    /** Default listing window, in seconds (7 days). */
    public const DEFAULT_WINDOW_SECONDS = 7 * 86400;
    /**
     * @param string $input value sent by the client
     * @return bool whether it is a plain "YYYY-MM-DD" date
     */
    public function isDateOnly(string $input): bool
    {
        return (new \OCA\Mcp\Platform\Dav\Icalendar\IsoDateInput())->isDateOnly($input);
    }
    /**
     * Parses a date or timestamp to UTC; a plain date means UTC midnight, as in JavaScript.
     *
     * @param string $input value sent by the client
     * @param string $label argument name used in the error message
     * @return DateTimeImmutable instant in UTC
     * @throws CalendarArgumentException when the value is not a valid ISO date
     */
    public function parse(string $input, string $label): DateTimeImmutable
    {
        try {
            return (new \OCA\Mcp\Platform\Dav\Icalendar\IsoDateInput())->parse($input, $label);
        } catch (\OCA\Mcp\Platform\Dav\Icalendar\DateArgumentException $e) {
            throw new CalendarArgumentException($e->getMessage(), $e->details()['field'], $e->details()['rule']);
        }
    }
    /**
     * Parses an all-day date.
     *
     * @param string $input value sent by the client
     * @param string $label argument name used in the error message
     * @return DateTimeImmutable the civil day at UTC midnight
     * @throws CalendarArgumentException when the value is not "YYYY-MM-DD"
     */
    public function day(string $input, string $label): DateTimeImmutable
    {
        if (!$this->isDateOnly($input)) {
            throw new CalendarArgumentException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::invalidAllDayFormat($label), $label, Translator::t('expected YYYY-MM-DD for an all-day date'));
        }
        return $this->parse($input, $label);
    }
    /**
     * Resolves the listing window; defaults to now until seven days later.
     *
     * @param string|null $from inclusive start
     * @param string|null $to exclusive end
     * @param DateTimeImmutable $now current time
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     * @throws CalendarArgumentException when a date is invalid or from is not before to
     * @throws CalendarException when the window exceeds 366 days
     */
    public function window(
        ?string $from,
        ?string $to,
        DateTimeImmutable $now,
    ): array
    {
        $start = $from === null ? $now->setTimezone(new DateTimeZone('UTC')) : $this->parse($from, 'from');
        $end = $to === null ? $start->modify('+' . self::DEFAULT_WINDOW_SECONDS . ' seconds') : $this->parse($to, 'to');
        if ($start >= $end) {
            throw new CalendarArgumentException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::rangeFromAfterTo(), 'to', Translator::t('must be after from'));
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            throw CalendarException::limit(\OCA\Mcp\Modules\Calendar\Events\EventMessages::windowTooWide());
        }
        return [$start, $end];
    }
    /**
     * Builds the timing of a new event from client strings.
     *
     * @param string $start start date or timestamp
     * @param string $end exclusive end date or timestamp
     * @param bool $allDay whether both must be "YYYY-MM-DD" civil days
     * @param string|null $timeZone IANA zone for timed events; ignored when all-day
     * @return EventTiming
     * @throws CalendarArgumentException when a value is invalid or end is not after start
     */
    public function timing(
        string $start,
        string $end,
        bool $allDay,
        ?string $timeZone,
    ): EventTiming
    {
        if ($allDay) {
            return new EventTiming(
                $this->day($start, 'start'),
                $this->day($end, 'end'),
                true,
                null
            );
        }
        $zone = $timeZone === null ? null : $this->timeZone($timeZone);
        return new EventTiming(
            $this->parse($start, 'start'),
            $this->parse($end, 'end'),
            false,
            $zone
        );
    }
    /**
     * @param string $name IANA time zone id
     * @return DateTimeZone
     * @throws CalendarArgumentException when the id is unknown
     */
    public function timeZone(string $name): DateTimeZone
    {
        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new CalendarArgumentException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::invalidTimeZone(), 'timeZone', Translator::t('expected an IANA time zone'));
        }
        return new DateTimeZone($name);
    }
}
