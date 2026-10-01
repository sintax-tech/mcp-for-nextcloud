<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;
use OCA\Mcp\Tools\RendersPlans;
use Throwable;

/**
 * Renders calendar write plans as human-readable Markdown.
 */
final class CalendarPlanRenderer implements RendersPlans {
    /** Longest description, invitation note or participant list shown in a plan; the full text is not needed to decide. */
    private const LONG_TEXT = 300;

    /**
     * @param string|null $forcedLocale optional locale override for testing (e.g. 'pt_BR', 'en')
     */
    public function __construct(private ?string $forcedLocale = null) {
    }

    /**
     * Renders a write plan as Markdown body.
     *
     * @param string $tool tool name
     * @param array<string, mixed> $plan plan array from preview()
     * @return string|null Markdown body (no title, no warnings, no footer), or null to use generic body
     */
    public function renderPlan(string $tool, array $plan): ?string {
        try {
            $action = (string)($plan['action'] ?? $tool);
            return match ($action) {
                'calendar_create_event' => $this->renderCreate($plan),
                'calendar_update_event' => $this->renderUpdate($plan),
                'calendar_move_event' => $this->renderMove($plan),
                'calendar_transfer_event' => $this->renderTransfer($plan),
                'calendar_delete_event' => $this->renderDelete($plan),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderCreate(array $plan): ?string {
        $calendarName = $this->calendarName($plan['calendar'] ?? null);
        $after = $plan['after'] ?? null;
        if (!is_array($after)) {
            return null;
        }

        $summary = $this->summary($after);
        $header = Translator::t('Create event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];

        $timing = $this->formatTiming(
            $after['start'] ?? null,
            $after['end'] ?? null,
            (bool)($after['allDay'] ?? false),
            $this->eventTimezone($plan),
            $this->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);

        $location = PlanText::inline($this->text($after['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }

        $description = PlanText::inline($this->text($after['description'] ?? null), self::LONG_TEXT);
        if ($description !== '') {
            $lines[] = Translator::t('- Description: %s', [$description]);
        }

        $participants = $this->participants($plan, $after);
        if ($participants !== '') {
            $lines[] = Translator::t('- Participants: %s', [$participants]);
        }

        $invitations = PlanText::inline($this->text($plan['scheduling']['message'] ?? null), self::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderUpdate(array $plan): ?string {
        $calendarName = $this->calendarName($plan['calendar'] ?? null);
        $before = $plan['before'] ?? null;
        $after = $plan['after'] ?? null;
        if (!is_array($before) || !is_array($after)) {
            return null;
        }

        $summary = $this->summary($after !== [] ? $after : $before);
        $header = Translator::t('Update event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];

        // 1. Title change
        $beforeSummary = $this->text($before['summary'] ?? null);
        $afterSummary = $this->text($after['summary'] ?? null);
        if ($beforeSummary !== $afterSummary) {
            $from = $beforeSummary !== '' ? PlanText::inline($beforeSummary) : Translator::t('(no title)');
            $to = $afterSummary !== '' ? PlanText::inline($afterSummary) : Translator::t('(no title)');
            $lines[] = Translator::t('- Title: %s → %s', [$from, $to]);
        }

        // 2. Timing change
        $timingChanged = ($before['start'] ?? null) !== ($after['start'] ?? null)
            || ($before['end'] ?? null) !== ($after['end'] ?? null)
            || (bool)($before['allDay'] ?? false) !== (bool)($after['allDay'] ?? false)
            || ($before['timeZone'] ?? null) !== ($after['timeZone'] ?? null);

        if ($timingChanged) {
            $beforeTiming = $this->formatTiming(
                $before['start'] ?? null,
                $before['end'] ?? null,
                (bool)($before['allDay'] ?? false),
                $before['timeZone'] ?? $this->eventTimezone($plan),
                $this->accountZone($plan)
            );
            $afterTiming = $this->formatTiming(
                $after['start'] ?? null,
                $after['end'] ?? null,
                (bool)($after['allDay'] ?? false),
                $after['timeZone'] ?? $this->eventTimezone($plan),
                $this->accountZone($plan)
            );
            if ($beforeTiming === null || $afterTiming === null) {
                return null;
            }
            $lines[] = Translator::t('- When: %s → %s', [$beforeTiming, $afterTiming]);
        }

        // 3. Location change
        $beforeLoc = $this->text($before['location'] ?? null);
        $afterLoc = $this->text($after['location'] ?? null);
        if ($beforeLoc !== $afterLoc) {
            $from = $beforeLoc !== '' ? PlanText::inline($beforeLoc) : Translator::t('(none)');
            $to = $afterLoc !== '' ? PlanText::inline($afterLoc) : Translator::t('(none)');
            $lines[] = Translator::t('- Location: %s → %s', [$from, $to]);
        }

        // 4. Description change
        $beforeDesc = $this->text($before['description'] ?? null);
        $afterDesc = $this->text($after['description'] ?? null);
        if ($beforeDesc !== $afterDesc) {
            $from = $beforeDesc !== '' ? PlanText::inline($beforeDesc, self::LONG_TEXT) : Translator::t('(none)');
            $to = $afterDesc !== '' ? PlanText::inline($afterDesc, self::LONG_TEXT) : Translator::t('(none)');
            $lines[] = Translator::t('- Description: %s → %s', [$from, $to]);
        }

        // 5. Participants change
        $beforeParts = $this->rawParticipants($plan['participants']['current'] ?? $before['attendees'] ?? [], $this->names($plan));
        $afterParts = $this->rawParticipants($plan['participants']['proposed'] ?? $after['attendees'] ?? [], $this->names($plan));
        if ($beforeParts !== $afterParts) {
            $from = !empty($beforeParts) ? PlanText::inline(implode(', ', $beforeParts), self::LONG_TEXT) : Translator::t('(none)');
            $to = !empty($afterParts) ? PlanText::inline(implode(', ', $afterParts), self::LONG_TEXT) : Translator::t('(none)');
            $lines[] = Translator::t('- Participants: %s → %s', [$from, $to]);
        }

        // 6. Scheduling message
        $invitations = PlanText::inline($this->text($plan['scheduling']['message'] ?? null), self::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }

        if (count($lines) === 1) {
            $lines[] = Translator::t('- No changes');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderMove(array $plan): ?string {
        $sourceName = $this->calendarName($plan['calendar'] ?? null);
        $destName = $this->calendarName($plan['destination'] ?? null);
        $event = $plan['after'] ?? $plan['before'] ?? null;
        if (!is_array($event)) {
            return null;
        }

        $summary = $this->summary($event);
        $header = Translator::t('Move event from *%s* to *%s*: **%s**', [$sourceName, $destName, $summary]);
        $lines = [$header];

        $timing = $this->formatTiming(
            $event['start'] ?? null,
            $event['end'] ?? null,
            (bool)($event['allDay'] ?? false),
            $this->eventTimezone($plan),
            $this->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);

        $location = PlanText::inline($this->text($event['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }

        $participants = $this->participants($plan, $event);
        if ($participants !== '') {
            $lines[] = Translator::t('- Participants: %s', [$participants]);
        }

        $invitations = PlanText::inline($this->text($plan['scheduling']['message'] ?? null), self::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderTransfer(array $plan): ?string {
        $sourceName = $this->calendarName($plan['calendar'] ?? null);
        $destName = $this->calendarName($plan['destination'] ?? null);
        $event = $plan['after'] ?? $plan['before'] ?? null;
        if (!is_array($event)) {
            return null;
        }

        $summary = $this->summary($event);
        $header = Translator::t('Transfer event from *%s* to *%s*: **%s**', [$sourceName, $destName, $summary]);
        $lines = [$header];

        $timing = $this->formatTiming(
            $event['start'] ?? null,
            $event['end'] ?? null,
            (bool)($event['allDay'] ?? false),
            $this->eventTimezone($plan),
            $this->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);

        $location = PlanText::inline($this->text($event['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }

        $participants = $this->participants($plan, $event);
        if ($participants !== '') {
            $lines[] = Translator::t('- Participants: %s', [$participants]);
        }

        $invitations = PlanText::inline($this->text($plan['scheduling']['message'] ?? null), self::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function renderDelete(array $plan): ?string {
        $calendarName = $this->calendarName($plan['calendar'] ?? null);
        $before = $plan['before'] ?? null;
        if (!is_array($before)) {
            return null;
        }

        $summary = $this->summary($before);
        $header = Translator::t('Delete event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];

        $timing = $this->formatTiming(
            $before['start'] ?? null,
            $before['end'] ?? null,
            (bool)($before['allDay'] ?? false),
            $this->eventTimezone($plan),
            $this->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);

        $consequence = PlanText::inline($this->text($plan['consequence'] ?? null), self::LONG_TEXT);
        if ($consequence !== '') {
            $lines[] = Translator::t('- Consequence: %s', [$consequence]);
        }

        $invitations = PlanText::inline($this->text($plan['scheduling']['message'] ?? null), self::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }

        return implode("\n", $lines);
    }

    /**
     * Formats start and end into a localized string with weekday, date, time and, when it is not the account's, the timezone.
     *
     * @param string|null $tzStr zone of the event itself, null when it was written with an offset only
     * @param string|null $accountTz zone the plan names for the account: the zone of an event without its own, and the one that needs no label
     */
    private function formatTiming(mixed $startVal, mixed $endVal, bool $allDay, ?string $tzStr, ?string $accountTz = null): ?string {
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

            $wf = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEE');
            $df = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');

            $formatDate = static function (DateTimeImmutable $d) use ($wf, $df): string {
                $weekday = rtrim((string)$wf->format($d), '.');
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

        $wf = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $tzTarget, null, 'EEE');
        $df = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $tzTarget);
        $tf = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $tzTarget);

        $formatDate = static function (DateTimeImmutable $d) use ($wf, $df): string {
            $weekday = rtrim((string)$wf->format($d), '.');
            return $weekday . ', ' . $df->format($d);
        };

        // The account's own zone is the default the person reads in; any other zone is named.
        $tzSuffix = $tzStr !== null && $tzStr !== '' && $tzStr !== $accountTz ? ' (' . $tzStr . ')' : '';

        // Same day in the event timezone
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            $timePart = $start->getTimestamp() === $end->getTimestamp()
                ? $tf->format($start)
                : $tf->format($start) . '–' . $tf->format($end);
            return $formatDate($start) . ', ' . $timePart . $tzSuffix;
        }

        // Multi-day timed event
        return $formatDate($start) . ', ' . $tf->format($start) . ' – ' . $formatDate($end) . ', ' . $tf->format($end) . $tzSuffix;
    }

    /**
     * @param array<string, mixed> $plan
     * @return string|null the account's timezone as the plan carries it, null when it is absent or not a zone
     */
    private function accountZone(array $plan): ?string {
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

    /**
     * @param array<string, mixed> $plan
     */
    private function eventTimezone(array $plan): ?string {
        $tz = $plan['after']['timeZone'] ?? $plan['before']['timeZone'] ?? null;
        return is_string($tz) && $tz !== '' ? $tz : null;
    }

    /**
     * @param mixed $calendar
     */
    private function calendarName(mixed $calendar): string {
        // Never the uri or the path: an internal id is not something the person recognizes.
        $name = is_array($calendar) ? PlanText::inline($this->text($calendar['name'] ?? null)) : '';
        return $name !== '' ? $name : Translator::t('unnamed calendar');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function summary(array $event): string {
        $summary = PlanText::inline($this->text($event['summary'] ?? null));
        return $summary !== '' ? $summary : Translator::t('(no title)');
    }

    /** @param mixed $value plan value @return string the trimmed text of a scalar, empty for anything else */
    private function text(mixed $value): string {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $event
     */
    private function participants(array $plan, array $event): string {
        $raw = $plan['participants']['proposed'] ?? $event['attendees'] ?? [];
        $list = $this->rawParticipants($raw, $this->names($plan));
        return PlanText::inline(implode(', ', $list), self::LONG_TEXT);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, string> display name by lower-case address, as the plan carries them
     */
    private function names(array $plan): array {
        $names = [];
        foreach ((array)($plan['participants']['names'] ?? []) as $email => $name) {
            if (is_string($name) && trim($name) !== '') {
                $names[strtolower((string)$email)] = trim($name);
            }
        }
        return $names;
    }

    /**
     * @param mixed $raw participants as the plan lists them: addresses (with or without `mailto:`) or ready-made labels
     * @param array<string, string> $names display name by lower-case address
     * @return list<string> the name when the plan has one, otherwise the bare address, never the `mailto:` prefix
     */
    private function rawParticipants(mixed $raw, array $names = []): array {
        if (!is_array($raw)) {
            return [];
        }
        $formatted = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $name = trim((string)($item['name'] ?? ''));
                $uid = trim((string)preg_replace('/^mailto:/i', '', trim((string)($item['uid'] ?? $item['email'] ?? ''))));
                if ($name !== '' && $uid !== '' && $name !== $uid) {
                    $formatted[] = $name . ' (' . $uid . ')';
                } elseif ($name !== '') {
                    $formatted[] = $name;
                } elseif ($uid !== '') {
                    $formatted[] = $uid;
                }
            } elseif (is_string($item) && trim($item) !== '') {
                $address = trim((string)preg_replace('/^mailto:/i', '', trim($item)));
                $formatted[] = $names[strtolower($address)] ?? $address;
            }
        }
        return array_values($formatted);
    }
}
