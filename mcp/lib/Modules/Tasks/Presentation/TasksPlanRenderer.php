<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Presentation;

final class TasksPlanRenderer
{
    public function render(string $tool, array $plan): ?string
    {
        $text = new TasksPlanText();
        $calendar = $text->name($plan['calendar'] ?? null);
        if ($calendar === null) {
            return null;
        }
        $zone = $text->zone($plan);
        $body = match ($tool) {
            'tasks_create_task' => (new TaskSaveRenderer($text))->create($calendar, $plan, $zone),
            'tasks_edit_task' => (new TaskSaveRenderer($text))->edit($calendar, $plan, $zone),
            'tasks_complete_task' => (new TaskSaveRenderer($text))->complete($calendar, $plan, $zone),
            'tasks_delete_task' => (new TaskDeletionRenderer($text))->delete($calendar, $plan),
            default => null,
        };
        if ($body === null) {
            return null;
        }
        return $text->shared($body, $plan, $calendar);
    }
}
