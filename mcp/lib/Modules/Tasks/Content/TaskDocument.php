<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Content;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use DateTimeInterface;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Reader;
use Throwable;
final class TaskDocument
{
    public function __construct(private TaskCategories $categories)
    {
    }
    public function parse(string $data): VCalendar
    {
        try {
            $calendar = Reader::read($data);
        } catch (Throwable $e) {
            throw new ToolFailure(Translator::t('The task could not be read safely.'), 0, $e);
        }
        if (!$calendar instanceof VCalendar || count($calendar->select('VTODO')) === 0 || count($calendar->select('VEVENT')) > 0) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        return $calendar;
    }
    public function master(VCalendar $calendar): VTodo
    {
        foreach ($calendar->select('VTODO') as $task) {
            if (!isset($task->{'RECURRENCE-ID'}) && (string) $task->UID !== '') {
                return $task;
            }
        }
        throw new ToolFailure(Translator::t('The task could not be read safely.'));
    }
    public function item(VCalendar $calendar): array
    {
        $task = $this->master($calendar);
        $date = static function ($property): ?string {
            if ($property === null) {
                return null;
            }
            try {
                return $property->getDateTime()->format($property->getValueType() === 'DATE' ? 'Y-m-d' : DateTimeInterface::ATOM);
            } catch (Throwable $e) {
                throw new ToolFailure(Translator::t('The task could not be read safely.'), 0, $e);
            }
        };
        return [
            'uid' => (string) $task->UID,
            'summary' => (string) $task->SUMMARY,
            'description' => (string) $task->DESCRIPTION,
            'status' => (string) $task->STATUS,
            'percentComplete' => (int) (string) $task->{'PERCENT-COMPLETE'},
            'priority' => (int) (string) $task->PRIORITY,
            'start' => $date($task->DTSTART),
            'due' => $date($task->DUE),
            'completed' => $date($task->COMPLETED),
            'categories' => $this->categories->categories($task),
            'recurring' => isset($task->RRULE) || isset($task->RDATE) || count($calendar->select('VTODO')) > 1,
            'icalendar' => $calendar->serialize(),
        ];
    }
}
