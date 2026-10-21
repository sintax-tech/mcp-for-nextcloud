<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Content;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use Sabre\VObject\Component\VCalendar;
final class TaskPatch
{
    public function __construct(
        private TaskDocument $document,
        private TaskCategories $categories,
        private TaskDates $dates,
    )
    {
    }
    public function create(array $arguments, DateTimeImmutable $now): VCalendar
    {
        $calendar = new VCalendar(
            [
                'VTODO' => [
                    'UID' => bin2hex(random_bytes(16)),
                    'SUMMARY' => $arguments['summary'],
                    'STATUS' => 'NEEDS-ACTION',
                ],
            ]
        );
        return $this->patch($calendar, $arguments, $now);
    }
    public function patch(
        VCalendar $original,
        array $arguments,
        DateTimeImmutable $now,
        bool $complete = false,
    ): VCalendar
    {
        // Refuse invalid categories before touching anything, so no write can be prepared from them.
        $categories = array_key_exists('categories', $arguments) ? $this->categories->validCategories($arguments['categories']) : null;
        $calendar = clone $original;
        $task = $this->document->master($calendar);
        if ($categories !== null) {
            // The list replaces every CATEGORIES line; one multi-value line escapes commas inside a name.
            unset($task->CATEGORIES);
            if ($categories !== []) {
                $task->add('CATEGORIES', $categories);
            }
        }
        foreach ([
            'summary' => 'SUMMARY',
            'description' => 'DESCRIPTION',
            'priority' => 'PRIORITY',
        ] as $argument => $property) {
            if (array_key_exists($argument, $arguments)) {
                if (isset($task->{$property})) {
                    $task->{$property}->setValue($arguments[$argument]);
                } else {
                    $task->add($property, $arguments[$argument]);
                }
            }
        }
        $this->dates->patch($task, $arguments);
        $stamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        if ($complete) {
            $task->STATUS = 'COMPLETED';
            $task->{'PERCENT-COMPLETE'} = 100;
            $task->COMPLETED = $stamp;
        } elseif (isset($arguments['percentComplete'])) {
            $percent = $arguments['percentComplete'];
            if ($percent < 0 || $percent > 100) {
                throw new InvalidArgumentException(Translator::t('Task progress must be between 0 and 100.'));
            }
            $task->{'PERCENT-COMPLETE'} = $percent;
            $task->STATUS = $percent === 100 ? 'COMPLETED' : ($percent === 0 ? 'NEEDS-ACTION' : 'IN-PROCESS');
            if ($percent === 100) {
                $task->COMPLETED = $stamp;
            } else {
                unset($task->COMPLETED);
            }
        }
        $task->DTSTAMP = $stamp;
        $task->{'LAST-MODIFIED'} = $stamp;
        $task->SEQUENCE = (int) (string) $task->SEQUENCE + 1;
        return $calendar;
    }
}
