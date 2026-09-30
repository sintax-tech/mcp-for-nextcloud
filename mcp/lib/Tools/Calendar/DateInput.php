<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses and validates the ISO dates, windows and time zones accepted by the calendar tools,
 * with the same formats as the Node prototype.
 */
final class DateInput {
    /** Largest listing window, in seconds (366 days). */
    public const MAX_WINDOW_SECONDS = 366 * 86400;
    /** Default listing window, in seconds (7 days). */
    public const DEFAULT_WINDOW_SECONDS = 7 * 86400;
    /** Date without time: "YYYY-MM-DD". */
    private const DATE_ONLY = '/^(\d{4})-(\d{2})-(\d{2})$/';
    /** Timestamp with mandatory "Z" or offset; seconds and milliseconds optional. */
    private const TIMESTAMP = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,3})?)?(Z|[+-]\d{2}:\d{2})$/i';

    /**
     * @param string $input value sent by the client
     * @return bool whether it is a plain "YYYY-MM-DD" date
     */
    public function isDateOnly(string $input): bool {
        return preg_match(self::DATE_ONLY, $input) === 1;
    }

    /**
     * Parses a date or timestamp to UTC; a plain date means UTC midnight, as in JavaScript.
     *
     * @param string $input value sent by the client
     * @param string $label argument name used in the error message
     * @return DateTimeImmutable instant in UTC
     * @throws CalendarArgumentException when the value is not a valid ISO date
     */
    public function parse(string $input, string $label): DateTimeImmutable {
        $utc = new DateTimeZone('UTC');
        if (preg_match(self::DATE_ONLY, $input, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return new DateTimeImmutable($input . 'T00:00:00', $utc);
        }
        if (preg_match(self::TIMESTAMP, $input, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1])
            && (int)$m[4] < 24 && (int)$m[5] < 60 && (int)($m[6] ?? 0) < 60) {
            $offset = strtoupper($m[7]) === 'Z' ? '+00:00' : $m[7];
            if ((int)substr($offset, 1, 2) < 24 && (int)substr($offset, 4, 2) < 60) {
                return (new DateTimeImmutable($input))->setTimezone($utc);
            }
        }
        throw new CalendarArgumentException("Data ISO inválida em {$label}.");
    }

    /**
     * Parses an all-day date.
     *
     * @param string $input value sent by the client
     * @param string $label argument name used in the error message
     * @return DateTimeImmutable the civil day at UTC midnight
     * @throws CalendarArgumentException when the value is not "YYYY-MM-DD"
     */
    public function day(string $input, string $label): DateTimeImmutable {
        if (!$this->isDateOnly($input)) {
            throw new CalendarArgumentException("Use o formato AAAA-MM-DD em {$label} para evento de dia inteiro.");
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
    public function window(?string $from, ?string $to, DateTimeImmutable $now): array {
        $start = $from === null ? $now->setTimezone(new DateTimeZone('UTC')) : $this->parse($from, 'from');
        $end = $to === null ? $start->modify('+' . self::DEFAULT_WINDOW_SECONDS . ' seconds') : $this->parse($to, 'to');
        if ($start >= $end) {
            throw new CalendarArgumentException('Intervalo inválido: from deve ser anterior a to.');
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_SECONDS) {
            throw CalendarException::limit('Janela do calendário excede o limite de 366 dias.');
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
    public function timing(string $start, string $end, bool $allDay, ?string $timeZone): EventTiming {
        if ($allDay) {
            return new EventTiming($this->day($start, 'start'), $this->day($end, 'end'), true, null);
        }
        $zone = $timeZone === null ? null : $this->timeZone($timeZone);
        return new EventTiming($this->parse($start, 'start'), $this->parse($end, 'end'), false, $zone);
    }

    /**
     * @param string $name IANA time zone id
     * @return DateTimeZone
     * @throws CalendarArgumentException when the id is unknown
     */
    public function timeZone(string $name): DateTimeZone {
        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new CalendarArgumentException('Fuso horário inválido em timeZone.');
        }
        return new DateTimeZone($name);
    }
}
