<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class EventChangeRenderer
{
    public function __construct(private CalendarPlanText $text)
    {
    }
    public function renderCreate(array $plan): ?string
    {
        $calendarName = $this->text->calendarName($plan['calendar'] ?? null);
        $after = $plan['after'] ?? null;
        if (!is_array($after)) {
            return null;
        }
        $summary = $this->text->summary($after);
        $header = Translator::t('Create event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];
        $timing = $this->text->formatTiming(
            $after['start'] ?? null,
            $after['end'] ?? null,
            (bool) ($after['allDay'] ?? false),
            $this->text->eventTimezone($plan),
            $this->text->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);
        $location = PlanText::inline($this->text->text($after['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }
        $description = PlanText::inline($this->text->text($after['description'] ?? null), CalendarPlanText::LONG_TEXT);
        if ($description !== '') {
            $lines[] = Translator::t('- Description: %s', [$description]);
        }
        $participants = $this->text->participants($plan, $after);
        if ($participants !== '') {
            $lines[] = Translator::t('- Participants: %s', [$participants]);
        }
        $invitations = PlanText::inline($this->text->text($plan['scheduling']['message'] ?? null), CalendarPlanText::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }
        array_push($lines, ...$this->text->sharedLines($plan));
        return implode("\n", $lines);
    }
    public function renderUpdate(array $plan): ?string
    {
        $calendarName = $this->text->calendarName($plan['calendar'] ?? null);
        $before = $plan['before'] ?? null;
        $after = $plan['after'] ?? null;
        if (!is_array($before) || !is_array($after)) {
            return null;
        }
        $summary = $this->text->summary($after !== [] ? $after : $before);
        $header = Translator::t('Update event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];
        // 1. Title change
        $beforeSummary = $this->text->text($before['summary'] ?? null);
        $afterSummary = $this->text->text($after['summary'] ?? null);
        if ($beforeSummary !== $afterSummary) {
            $from = $beforeSummary !== '' ? PlanText::inline($beforeSummary) : Translator::t('(no title)');
            $to = $afterSummary !== '' ? PlanText::inline($afterSummary) : Translator::t('(no title)');
            $lines[] = Translator::t('- Title: %s → %s', [$from, $to]);
        }
        // 2. Timing change
        $timingChanged = ($before['start'] ?? null) !== ($after['start'] ?? null) || ($before['end'] ?? null) !== ($after['end'] ?? null) || (bool) ($before['allDay'] ?? false) !== (bool) ($after['allDay'] ?? false) || ($before['timeZone'] ?? null) !== ($after['timeZone'] ?? null);
        if ($timingChanged) {
            $beforeTiming = $this->text->formatTiming(
                $before['start'] ?? null,
                $before['end'] ?? null,
                (bool) ($before['allDay'] ?? false),
                $before['timeZone'] ?? $this->text->eventTimezone($plan),
                $this->text->accountZone($plan)
            );
            $afterTiming = $this->text->formatTiming(
                $after['start'] ?? null,
                $after['end'] ?? null,
                (bool) ($after['allDay'] ?? false),
                $after['timeZone'] ?? $this->text->eventTimezone($plan),
                $this->text->accountZone($plan)
            );
            if ($beforeTiming === null || $afterTiming === null) {
                return null;
            }
            $lines[] = Translator::t('- When: %s → %s', [$beforeTiming, $afterTiming]);
        }
        // 3. Location change
        $beforeLoc = $this->text->text($before['location'] ?? null);
        $afterLoc = $this->text->text($after['location'] ?? null);
        if ($beforeLoc !== $afterLoc) {
            $from = $beforeLoc !== '' ? PlanText::inline($beforeLoc) : Translator::t('(none)');
            $to = $afterLoc !== '' ? PlanText::inline($afterLoc) : Translator::t('(none)');
            $lines[] = Translator::t('- Location: %s → %s', [$from, $to]);
        }
        // 4. Description change
        $beforeDesc = $this->text->text($before['description'] ?? null);
        $afterDesc = $this->text->text($after['description'] ?? null);
        if ($beforeDesc !== $afterDesc) {
            $from = $beforeDesc !== '' ? PlanText::inline($beforeDesc, CalendarPlanText::LONG_TEXT) : Translator::t('(none)');
            $to = $afterDesc !== '' ? PlanText::inline($afterDesc, CalendarPlanText::LONG_TEXT) : Translator::t('(none)');
            $lines[] = Translator::t('- Description: %s → %s', [$from, $to]);
        }
        // 5. Participants change
        $beforeParts = $this->text->rawParticipants($plan['participants']['current'] ?? $before['attendees'] ?? [], $this->text->names($plan));
        $afterParts = $this->text->rawParticipants($plan['participants']['proposed'] ?? $after['attendees'] ?? [], $this->text->names($plan));
        if ($beforeParts !== $afterParts) {
            $from = !empty($beforeParts) ? PlanText::inline(implode(', ', $beforeParts), CalendarPlanText::LONG_TEXT) : Translator::t('(none)');
            $to = !empty($afterParts) ? PlanText::inline(implode(', ', $afterParts), CalendarPlanText::LONG_TEXT) : Translator::t('(none)');
            $lines[] = Translator::t('- Participants: %s → %s', [$from, $to]);
        }
        // 6. Scheduling message
        $invitations = PlanText::inline($this->text->text($plan['scheduling']['message'] ?? null), CalendarPlanText::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }
        if (count($lines) === 1) {
            $lines[] = Translator::t('- No changes');
        }
        array_push($lines, ...$this->text->sharedLines($plan));
        return implode("\n", $lines);
    }
}
