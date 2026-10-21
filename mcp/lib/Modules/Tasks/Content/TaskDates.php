<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Content;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\Dav\Icalendar\IsoDateInput as DateInput;
use Sabre\VObject\Component\VTodo;
use Throwable;
final class TaskDates
{
    public function patch(VTodo $task, array $arguments): void
    {
        $dates = new DateInput();
        foreach (['start' => 'DTSTART', 'due' => 'DUE'] as $argument => $property) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }
            if ($arguments[$argument] === null) {
                unset($task->{$property});
                continue;
            }
            $value = $dates->parse($arguments[$argument], $argument);
            unset($task->{$property});
            if ($dates->isDateOnly($arguments[$argument])) {
                $task->add($property, $value->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $task->add($property, $value->format('Ymd\THis\Z'));
            }
        }
        // DTSTART and DUE must use the same value type; DUE cannot precede DTSTART.
        if (isset($task->DTSTART) && isset($task->DUE)) {
            if ($task->DTSTART->getValueType() !== $task->DUE->getValueType() || $task->DTSTART->getDateTime() > $task->DUE->getDateTime()) {
                throw new InvalidArgumentException(Translator::t('Task due date must not precede its start and must use the same date type.'));
            }
        }
        // DURATION is an alternative to DUE; a supplied due date intentionally replaces it.
        if (array_key_exists('due', $arguments) && $arguments['due'] !== null) {
            unset($task->DURATION);
        }
        if (isset($task->DURATION) && !isset($task->DTSTART)) {
            throw new InvalidArgumentException(
                Translator::t('A task with a duration requires a start. Set a due date before clearing the start.')
            );
        }
        // Sabre's CalDAV validation does not check relative alarm dependencies.
        // Preserve alarms, but refuse date edits that leave their native trigger unusable.
        if (array_key_exists('start', $arguments) || array_key_exists('due', $arguments)) {
            foreach ($task->select('VALARM') as $alarm) {
                if (!isset($alarm->TRIGGER) || $alarm->TRIGGER->getValueType() !== 'DURATION') {
                    continue;
                }
                try {
                    $alarm->getEffectiveTriggerTime();
                } catch (Throwable $e) {
                    throw new InvalidArgumentException(
                        Translator::t('The task dates are required by a relative reminder. Keep a date for the reminder.'),
                        0,
                        $e
                    );
                }
            }
        }
    }
}
