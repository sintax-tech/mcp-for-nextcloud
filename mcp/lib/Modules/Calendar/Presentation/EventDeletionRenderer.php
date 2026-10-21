<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class EventDeletionRenderer
{
    public function __construct(private CalendarPlanText $text)
    {
    }
    public function renderDelete(array $plan): ?string
    {
        $calendarName = $this->text->calendarName($plan['calendar'] ?? null);
        $before = $plan['before'] ?? null;
        if (!is_array($before)) {
            return null;
        }
        $summary = $this->text->summary($before);
        $header = Translator::t('Delete event in *%s*: **%s**', [$calendarName, $summary]);
        $lines = [$header];
        $timing = $this->text->formatTiming(
            $before['start'] ?? null,
            $before['end'] ?? null,
            (bool) ($before['allDay'] ?? false),
            $this->text->eventTimezone($plan),
            $this->text->accountZone($plan)
        );
        if ($timing === null) {
            return null;
        }
        $lines[] = Translator::t('- When: %s', [$timing]);
        $consequence = PlanText::inline($this->text->text($plan['consequence'] ?? null), CalendarPlanText::LONG_TEXT);
        if ($consequence !== '') {
            $lines[] = Translator::t('- Consequence: %s', [$consequence]);
        }
        $invitations = PlanText::inline($this->text->text($plan['scheduling']['message'] ?? null), CalendarPlanText::LONG_TEXT);
        if ($invitations !== '') {
            $lines[] = Translator::t('- Invitations: %s', [$invitations]);
        }
        array_push($lines, ...$this->text->sharedLines($plan));
        return implode("\n", $lines);
    }
}
