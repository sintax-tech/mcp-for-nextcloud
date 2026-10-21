<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Presentation;

use OCA\Mcp\L10n\Translator;
final class TaskDeletionRenderer
{
    public function __construct(private TasksPlanText $text)
    {
    }
    public function delete(string $calendar, array $plan): ?string
    {
        $before = $this->text->task($plan['before'] ?? null);
        $summary = $before === null ? '' : $this->text->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [
            Translator::t(
                'Moving the task %s to the trash of the calendar %s.',
                [$this->text->strong($summary), $this->text->emphasis($calendar)]
            ),
        ];
        $lines[] = Translator::t('The task stays in the calendar trash, where you can bring it back.');
        return implode("\n", $lines);
    }
}
