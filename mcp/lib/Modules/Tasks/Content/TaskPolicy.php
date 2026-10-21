<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Content;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;
final class TaskPolicy
{
    public function __construct(private TaskDocument $document)
    {
    }
    public function visible(VCalendar $calendar, bool $shared): bool
    {
        if (!$shared) {
            return true;
        }
        // Hide protected tasks completely: unlike an event there is no useful busy-only view.
        foreach ($calendar->select('VTODO') as $task) {
            if (in_array(strtoupper((string) $task->CLASS), ['PRIVATE', 'CONFIDENTIAL'], true)) {
                return false;
            }
        }
        return true;
    }
    public function assertSimple(VCalendar $calendar): void
    {
        $task = $this->document->master($calendar);
        if (count($calendar->select('VTODO')) !== 1 || isset($task->RRULE) || isset($task->RDATE) || isset($task->EXDATE) || isset($task->ORGANIZER) || count($task->select('ATTENDEE')) > 0) {
            throw new ToolFailure(
                Translator::t('Recurring tasks and tasks with participants cannot be changed by this tool. Use the Tasks interface.')
            );
        }
    }
}
