<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use Throwable;
final class CalendarPlanText
{
    public const LONG_TEXT = 300;
    public function __construct(private ?string $forcedLocale = null)
    {
    }
    public function sharedLines(array $plan): array
    {
        $lines = [];
        foreach (is_array($plan['shared'] ?? null) ? $plan['shared'] : [] as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $name = PlanText::inline($this->text($notice['resource'] ?? null));
            $owner = PlanText::inline($this->text($notice['ownerDisplayName'] ?? $notice['owner'] ?? null));
            if ($owner !== '') {
                $lines[] = $name !== '' ? Translator::t('- Shared calendar *%s* of %s.', [$name, $owner]) : Translator::t('- Shared calendar of %s.', [$owner]);
            }
        }
        return $lines;
    }
    public function formatTiming(
        mixed $startVal,
        mixed $endVal,
        bool $allDay,
        ?string $tzStr,
        ?string $accountTz = null,
    ): ?string
    {
        if (!is_string($startVal) || !is_string($endVal) || $startVal === '' || $endVal === '') {
            return null;
        }
        $locale = $this->forcedLocale ?? Translator::locale();
        if ($allDay) {
            $startSub = substr($startVal, 0, 10);
            $endSub = substr($endVal, 0, 10);
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startSub, new DateTimeZone('UTC'));
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endSub, new DateTimeZone('UTC'));
            if ($start === false || $end === false || $end < $start) {
                return null;
            }
            $diff = $start->diff($end);
            $days = $diff->days;
            $wf = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                'UTC',
                null,
                'EEE'
            );
            $df = new IntlDateFormatter(
                $locale,
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::NONE,
                'UTC'
            );
            $formatDate = static function (DateTimeImmutable $d) use ($wf, $df): string {
                $weekday = rtrim((string) $wf->format($d), '.');
                return $weekday . ', ' . $df->format($d);
            };
            // Single-day all-day event (end is exclusive, duration <= 1 day)
            if ($days <= 1) {
                return $formatDate($start);
            }
            // Multi-day all-day event (exclusive end date minus 1 day)
            $inclusiveEnd = $end->modify('-1 day');
            return $formatDate($start) . ' – ' . $formatDate($inclusiveEnd);
        }
        $tz = null;
        if ($tzStr !== null && $tzStr !== '') {
            try {
                $tz = new DateTimeZone($tzStr);
            } catch (Throwable) {
                return null;
            }
        }
        $tzTarget = $tz ?? ($accountTz !== null ? new DateTimeZone($accountTz) : new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        try {
            $start = new DateTimeImmutable($startVal);
            $end = new DateTimeImmutable($endVal);
        } catch (Throwable) {
            return null;
        }
        if ($end < $start) {
            return null;
        }
        $start = $start->setTimezone($tzTarget);
        $end = $end->setTimezone($tzTarget);
        $wf = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $tzTarget,
            null,
            'EEE'
        );
        $df = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE,
            $tzTarget
        );
        $tf = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::SHORT,
            $tzTarget
        );
        $formatDate = static function (DateTimeImmutable $d) use ($wf, $df): string {
            $weekday = rtrim((string) $wf->format($d), '.');
            return $weekday . ', ' . $df->format($d);
        };
        // The account's own zone is the default the person reads in; any other zone is named.
        $tzSuffix = $tzStr !== null && $tzStr !== '' && $tzStr !== $accountTz ? ' (' . $tzStr . ')' : '';
        // Same day in the event timezone
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            $timePart = $start->getTimestamp() === $end->getTimestamp() ? $tf->format($start) : $tf->format($start) . '–' . $tf->format($end);
            return $formatDate($start) . ', ' . $timePart . $tzSuffix;
        }
        // Multi-day timed event
        return $formatDate($start) . ', ' . $tf->format($start) . ' – ' . $formatDate($end) . ', ' . $tf->format($end) . $tzSuffix;
    }
    public function accountZone(array $plan): ?string
    {
        $name = $this->text($plan['timezone'] ?? null);
        if ($name === '') {
            return null;
        }
        try {
            new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
        return $name;
    }
    public function eventTimezone(array $plan): ?string
    {
        $tz = $plan['after']['timeZone'] ?? $plan['before']['timeZone'] ?? null;
        return is_string($tz) && $tz !== '' ? $tz : null;
    }
    public function calendarName(mixed $calendar): string
    {
        // Never the uri or the path: an internal id is not something the person recognizes.
        $name = is_array($calendar) ? PlanText::inline($this->text($calendar['name'] ?? null)) : '';
        return $name !== '' ? $name : Translator::t('unnamed calendar');
    }
    public function summary(array $event): string
    {
        $summary = PlanText::inline($this->text($event['summary'] ?? null));
        return $summary !== '' ? $summary : Translator::t('(no title)');
    }
    public function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
    public function participants(array $plan, array $event): string
    {
        $raw = $plan['participants']['proposed'] ?? $event['attendees'] ?? [];
        $list = $this->rawParticipants($raw, $this->names($plan));
        return PlanText::inline(implode(', ', $list), self::LONG_TEXT);
    }
    public function names(array $plan): array
    {
        $names = [];
        foreach ((array) ($plan['participants']['names'] ?? []) as $email => $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[strtolower((string) $email)] = trim($name);
            }
        }
        return $names;
    }
    public function rawParticipants(mixed $raw, array $names = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $formatted = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                $uid = trim((string) preg_replace('/^mailto:/i', '', trim((string) ($item['uid'] ?? $item['email'] ?? ''))));
                if ($name !== '' && $uid !== '' && $name !== $uid) {
                    $formatted[] = $name . ' (' . $uid . ')';
                } elseif ($name !== '') {
                    $formatted[] = $name;
                } elseif ($uid !== '') {
                    $formatted[] = $uid;
                }
            } elseif (is_string($item) && trim($item) !== '') {
                $address = trim((string) preg_replace('/^mailto:/i', '', trim($item)));
                $formatted[] = $names[strtolower($address)] ?? $address;
            }
        }
        return array_values($formatted);
    }
}
