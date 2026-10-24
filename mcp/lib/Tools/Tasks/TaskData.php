<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Throwable;

/** Reads VTODO and patches a simple task without discarding unrelated iCalendar properties. */
class TaskData {
    /** Most categories one task write accepts, as many as the tool schema allows. */
    public const MAX_CATEGORIES = 50;

    /** Longest category, in characters after trimming. */
    public const MAX_CATEGORY_LENGTH = 255;

    /** Most parent links followed when checking that a new parent does not make the hierarchy a loop. */
    public const MAX_DEPTH = 64;

    /**
     * Reads a calendar containing tasks and rejects mixed event/task objects.
     *
     * @param string $data complete serialized DAV object
     * @return VCalendar
     * @throws ToolFailure when the object cannot be read safely as a task calendar
     */
    public function parse(string $data): VCalendar {
        try {
            $calendar = Reader::read($data);
        } catch (Throwable $e) {
            throw new ToolFailure(Translator::t('The task could not be read safely.'), 0, $e);
        }
        if (!$calendar instanceof VCalendar || count($calendar->select('VTODO')) === 0 || count($calendar->select('VEVENT')) > 0) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        return $calendar;
    }

    /**
     * Finds the task with a UID that is not a recurrence override.
     *
     * @param VCalendar $calendar parsed task calendar
     * @return VTodo
     * @throws ToolFailure when no task master with a UID exists
     */
    public function master(VCalendar $calendar): VTodo {
        foreach ($calendar->select('VTODO') as $task) {
            if (!isset($task->{'RECURRENCE-ID'}) && (string) $task->UID !== '') {
                return $task;
            }
        }
        throw new ToolFailure(Translator::t('The task could not be read safely.'));
    }

    /**
     * Hides private or confidential tasks when the calendar belongs to another user.
     *
     * @param VCalendar $calendar parsed task calendar
     * @param bool $shared whether the calendar belongs to another user
     * @return bool
     */
    public function visible(VCalendar $calendar, bool $shared): bool {
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

    /**
     * Rejects recurring tasks and participants before any task write is prepared.
     *
     * @param VCalendar $calendar parsed task calendar
     * @return void
     * @throws ToolFailure when recurrence or participants make the write unsupported
     */
    public function assertSimple(VCalendar $calendar): void {
        $task = $this->master($calendar);
        if (
            count($calendar->select('VTODO')) !== 1
            || isset($task->RRULE)
            || isset($task->RDATE)
            || isset($task->EXDATE)
            || isset($task->ORGANIZER)
            || count($task->select('ATTENDEE')) > 0
        ) {
            throw new ToolFailure(
                Translator::t(
                    'Recurring tasks and tasks with participants cannot be changed by this tool. Use the Tasks interface.'
                )
            );
        }
    }

    /**
     * Creates a task with a new UID and applies the supplied fields and timestamps.
     *
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @param DateTimeImmutable $now clock time for task metadata
     * @return VCalendar
     * @throws InvalidArgumentException when task dates, progress or categories are invalid
     * @throws ToolFailure when the task cannot be read safely
     */
    public function create(array $arguments, DateTimeImmutable $now): VCalendar {
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

    /**
     * Clones a task, preserving unrelated properties and validating temporal dependencies.
     *
     * @param VCalendar $original original object, which remains unchanged
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @param DateTimeImmutable $now clock time for task metadata
     * @param bool $complete whether to mark the task completed
     * @return VCalendar
     * @throws InvalidArgumentException when dates, progress, categories or reminder dependencies are invalid
     * @throws ToolFailure when no safe task master exists
     */
    public function patch(
        VCalendar $original,
        array $arguments,
        DateTimeImmutable $now,
        bool $complete = false,
    ): VCalendar {
        // Refuse invalid categories before touching anything, so no write can be prepared from them.
        $categories = array_key_exists('categories', $arguments) ? $this->validCategories($arguments['categories']) : null;
        $calendar = clone $original;
        $task = $this->master($calendar);
        if ($categories !== null) {
            // The list replaces every CATEGORIES line; one multi-value line escapes commas inside a name.
            unset($task->CATEGORIES);
            if ($categories !== []) {
                $task->add('CATEGORIES', $categories);
            }
        }
        if (array_key_exists('parent', $arguments)) {
            $parent = $arguments['parent'];
            if ($parent !== null && $parent === (string) $task->UID) {
                throw new InvalidArgumentException(
                    Translator::t('A task cannot be its own parent or a subtask of one of its own subtasks.')
                );
            }
            // Only the parent link is replaced: sibling, child or custom relations of the task are kept.
            foreach ($task->select('RELATED-TO') as $relation) {
                if ($this->isParentRelation($relation)) {
                    $task->remove($relation);
                }
            }
            if ($parent !== null) {
                $task->add('RELATED-TO', $parent, ['RELTYPE' => 'PARENT']);
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
            if (
                $task->DTSTART->getValueType() !== $task->DUE->getValueType()
                || $task->DTSTART->getDateTime() > $task->DUE->getDateTime()
            ) {
                throw new InvalidArgumentException(
                    Translator::t('Task due date must not precede its start and must use the same date type.')
                );
            }
        }
        // DURATION is an alternative to DUE; a supplied due date intentionally replaces it.
        if (array_key_exists('due', $arguments) && $arguments['due'] !== null) {
            unset($task->DURATION);
        }
        if (isset($task->DURATION) && !isset($task->DTSTART)) {
            throw new InvalidArgumentException(
                Translator::t(
                    'A task with a duration requires a start. Set a due date before clearing the start.'
                )
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
                        Translator::t(
                            'The task dates are required by a relative reminder. Keep a date for the reminder.'
                        ),
                        0,
                        $e
                    );
                }
            }
        }
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

    /**
     * Returns the task fields and the full serialized calendar, retaining unknown properties.
     *
     * @param VCalendar $calendar parsed task calendar
     * @return array<string, mixed>
     * @throws ToolFailure when task dates or identity cannot be read safely
     */
    public function item(VCalendar $calendar): array {
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
            'categories' => $this->categories($task),
            'parent' => $this->parent($task),
            'recurring' => isset($task->RRULE) || isset($task->RDATE) || count($calendar->select('VTODO')) > 1,
            'icalendar' => $calendar->serialize(),
        ];
    }

    /**
     * Reads the UID of the parent task, as the Tasks app links a subtask to it.
     *
     * RELATED-TO without RELTYPE is a parent link too: PARENT is the default relation type of RFC 5545.
     *
     * @param VTodo $task task master
     * @return string|null UID of the parent task, or null for a top-level task
     */
    public function parent(VTodo $task): ?string {
        foreach ($task->select('RELATED-TO') as $relation) {
            $uid = trim((string) $relation);
            if ($uid !== '' && $this->isParentRelation($relation)) {
                return $uid;
            }
        }
        return null;
    }

    /**
     * Reads the categories of every CATEGORIES line of the task, honouring escaped commas.
     *
     * @param VTodo $task task master
     * @return list<string> normalized categories, empty when the task has none
     */
    public function categories(VTodo $task): array {
        $values = [];
        foreach ($task->select('CATEGORIES') as $property) {
            foreach ($property->getParts() as $part) {
                $values[] = (string) $part;
            }
        }
        return $this->normalizeCategories($values);
    }

    /**
     * Validates the categories argument of a write and returns it normalized.
     *
     * @param mixed $value supplied categories argument
     * @return list<string> normalized categories; an empty list clears them
     * @throws InvalidArgumentException when the value is not a bounded list of short texts without control characters
     */
    public function validCategories(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_CATEGORIES) {
            throw new InvalidArgumentException(
                Translator::t('Task categories must be a list of at most %s texts.', [self::MAX_CATEGORIES])
            );
        }
        foreach ($value as $category) {
            $length = is_string($category) ? mb_strlen(trim($category)) : 0;
            // preg_match() returns false for invalid UTF-8, which is refused like a control character.
            if ($length < 1 || $length > self::MAX_CATEGORY_LENGTH || preg_match('/\p{Cc}/u', $category) !== 0) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'Each task category must have 1 to %s characters and no control characters.',
                        [self::MAX_CATEGORY_LENGTH]
                    )
                );
            }
        }
        return $this->normalizeCategories($value);
    }

    /**
     * Tells whether a task item carries a category, comparing the case-folded names.
     *
     * @param array<string, mixed> $item task fields returned by item()
     * @param string $category category to look for
     * @return bool
     */
    public function hasCategory(array $item, string $category): bool {
        $wanted = $this->foldCategory(trim($category));
        foreach ($item['categories'] ?? [] as $candidate) {
            if ($this->foldCategory($candidate) === $wanted) {
                return true;
            }
        }
        return false;
    }

    /**
     * Trims categories, drops empty ones and keeps the first spelling of names that differ only in case.
     *
     * @param list<string> $values raw categories in their original order
     * @return list<string>
     */
    private function normalizeCategories(array $values): array {
        $seen = [];
        $categories = [];
        foreach ($values as $value) {
            $category = trim($value);
            $key = $this->foldCategory($category);
            if ($category === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $categories[] = $category;
        }
        return $categories;
    }

    /**
     * Tells whether a RELATED-TO line links the task to its parent.
     *
     * @param Property $relation RELATED-TO line of the task
     * @return bool
     */
    private function isParentRelation(Property $relation): bool {
        return !isset($relation['RELTYPE']) || strtoupper((string) $relation['RELTYPE']) === 'PARENT';
    }

    /**
     * Folds a category to the Unicode case-folded form two names are compared in.
     *
     * @param string $category trimmed category
     * @return string
     */
    private function foldCategory(string $category): string {
        // Full case folding, unlike lowercasing, makes "Σ", "σ" and the final "ς" one name.
        return mb_convert_case($category, MB_CASE_FOLD, 'UTF-8');
    }
}
