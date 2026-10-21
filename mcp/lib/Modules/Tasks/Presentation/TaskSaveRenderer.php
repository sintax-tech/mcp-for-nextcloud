<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Presentation;

use DateTimeZone;
use OCA\Mcp\L10n\Translator;
final class TaskSaveRenderer
{
    public function __construct(private TasksPlanText $text)
    {
    }
    public function create(
        string $calendar,
        array $plan,
        DateTimeZone $zone,
    ): ?string
    {
        $after = $this->text->task($plan['after'] ?? null);
        $summary = $after === null ? '' : $this->text->text($after['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Creating the task %s in the calendar %s.',
                [$this->text->strong($summary), $this->text->emphasis($calendar)]
            ),
        ];
        foreach (TasksPlanText::FIELDS as $field) {
            if ($field === 'summary') {
                continue;
            }
            $value = $this->text->value($after[$field] ?? null, $field, $zone);
            if ($value !== null) {
                $lines[] = '- ' . $this->text->label($field) . ': ' . $value;
            }
        }
        return implode("\n", $lines);
    }
    public function edit(
        string $calendar,
        array $plan,
        DateTimeZone $zone,
    ): ?string
    {
        $before = $this->text->task($plan['before'] ?? null);
        $after = $this->text->task($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $summary = $this->text->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Editing the task %s in the calendar %s.',
                [$this->text->strong($summary), $this->text->emphasis($calendar)]
            ),
        ];
        return $this->text->changes(
            $lines,
            $before,
            $after,
            $zone
        );
    }
    public function complete(
        string $calendar,
        array $plan,
        DateTimeZone $zone,
    ): ?string
    {
        $before = $this->text->task($plan['before'] ?? null);
        $after = $this->text->task($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $summary = $this->text->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Marking the task %s as completed in the calendar %s.',
                [$this->text->strong($summary), $this->text->emphasis($calendar)]
            ),
        ];
        $progress = $this->text->value($after['percentComplete'] ?? null, 'percentComplete', $zone);
        if ($progress !== null) {
            $lines[] = '- ' . Translator::t('Progress') . ': ' . $progress;
        }
        $completed = $this->text->value($after['completed'] ?? null, 'completed', $zone);
        if ($completed !== null) {
            $lines[] = '- ' . Translator::t('Completed at') . ': ' . $completed;
        }
        return implode("\n", $lines);
    }
}
