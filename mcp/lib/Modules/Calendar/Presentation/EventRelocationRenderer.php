<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class EventRelocationRenderer
{
    public function __construct(private CalendarPlanText $text)
    {
    }
    public function renderMove(array $plan): ?string
    {
        $sourceName = $this->text->calendarName($plan['calendar'] ?? null);
        $destName = $this->text->calendarName($plan['destination'] ?? null);
        $event = $plan['after'] ?? $plan['before'] ?? null;
        if (!is_array($event)) {
            return null;
        }
        $summary = $this->text->summary($event);
        $header = Translator::t('Move event from *%s* to *%s*: **%s**', [$sourceName, $destName, $summary]);
        $lines = [$header];
        $timing = $this->text->formatTiming(
            $event['start'] ?? null,
            $event['end'] ?? null,
            (bool) ($event['allDay'] ?? false),
            $this->text->eventTimezone($plan),
            $this->text->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);
        $location = PlanText::inline($this->text->text($event['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }
        $participants = $this->text->participants($plan, $event);
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
    public function renderTransfer(array $plan): ?string
    {
        $sourceName = $this->text->calendarName($plan['calendar'] ?? null);
        $destName = $this->text->calendarName($plan['destination'] ?? null);
        $event = $plan['after'] ?? $plan['before'] ?? null;
        if (!is_array($event)) {
            return null;
        }
        $summary = $this->text->summary($event);
        $header = Translator::t('Transfer event from *%s* to *%s*: **%s**', [$sourceName, $destName, $summary]);
        $lines = [$header];
        $timing = $this->text->formatTiming(
            $event['start'] ?? null,
            $event['end'] ?? null,
            (bool) ($event['allDay'] ?? false),
            $this->text->eventTimezone($plan),
            $this->text->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);
        $location = PlanText::inline($this->text->text($event['location'] ?? null));
        if ($location !== '') {
            $lines[] = Translator::t('- Location: %s', [$location]);
        }
        $participants = $this->text->participants($plan, $event);
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
}
