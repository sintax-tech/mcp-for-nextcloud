<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use Throwable;
final class CalendarPlanRenderer implements RendersPlans
{
    public function __construct(private ?string $forcedLocale = null)
    {
    }
    public function renderPlan(string $tool, array $plan): ?string
    {
        try {
            $action = (string) ($plan['action'] ?? $tool);
            return match ($action) {
                'calendar_create_event' => (new EventChangeRenderer(new CalendarPlanText($this->forcedLocale)))->renderCreate($plan),
                'calendar_update_event' => (new EventChangeRenderer(new CalendarPlanText($this->forcedLocale)))->renderUpdate($plan),
                'calendar_move_event' => (new EventRelocationRenderer(new CalendarPlanText($this->forcedLocale)))->renderMove($plan),
                'calendar_transfer_event' => (new EventRelocationRenderer(new CalendarPlanText($this->forcedLocale)))->renderTransfer($plan),
                'calendar_delete_event' => (new EventDeletionRenderer(new CalendarPlanText($this->forcedLocale)))->renderDelete($plan),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
