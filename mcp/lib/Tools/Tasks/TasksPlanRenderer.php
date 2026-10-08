<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/**
 * Writes the plan of a task write as the text the person asked to confirm reads.
 *
 * The module already knows everything the plan carries, so this class only decides what to say:
 * the task by the title its author gave it, the calendar it lives in, and only the fields an
 * edit really changes. Dates reach the reader in the timezone the plan names, because a due date
 * that moves a day when it crosses the Atlantic is a date the person cannot trust.
 *
 * The timezone is read from the plan and nowhere else: this renderer holds no account, so it can
 * render any plan without knowing which request it came from.
 *
 * A plan that does not carry what this renderer expects gives back `null`, and the caller falls
 * back to the generic body, which is worse but never missing.
 */
final class TasksPlanRenderer {
    /** Longest excerpt of a long text (a description) the body shows; the plan keeps the whole text. */
    private const EXCERPT = 200;

    /**
     * Task fields a person reads, in the order a task shows them.
     *
     * @var list<string>
     */
    private const FIELDS = ['summary', 'description', 'start', 'due', 'priority', 'percentComplete'];

    /**
     * @param string $tool write tool of this module
     * @param array<string, mixed> $plan plan returned by TasksModule::preview()
     * @return string|null Markdown body, or null when the plan lacks what this renderer needs
     */
    public function render(string $tool, array $plan): ?string {
        $calendar = $this->name($plan['calendar'] ?? null);
        if ($calendar === null) {
            return null;
        }
        $zone = $this->zone($plan);
        $body = match ($tool) {
            'tasks_create_task' => $this->create($calendar, $plan, $zone),
            'tasks_edit_task' => $this->edit($calendar, $plan, $zone),
            'tasks_complete_task' => $this->complete($calendar, $plan, $zone),
            'tasks_delete_task' => $this->delete($calendar, $plan),
            default => null,
        };
        if ($body === null) {
            return null;
        }
        return $this->shared($body, $plan, $calendar);
    }

    /**
     * What the new task will hold: the fields with a value, as a person would read them.
     *
     * @param string $calendar display name of the calendar the task goes into
     * @param array<string, mixed> $plan the plan of a create
     * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
     * @return string|null the body, or null when the task has no title to show
     */
    private function create(string $calendar, array $plan, DateTimeZone $zone): ?string {
        $after = $this->task($plan['after'] ?? null);
        $summary = $after === null ? '' : $this->text($after['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [Translator::t(
            'Creating the task %s in the calendar %s.',
            [$this->strong($summary), $this->emphasis($calendar)]
        )];
        foreach (self::FIELDS as $field) {
            if ($field === 'summary') {
                continue;
            }
            $value = $this->value($after[$field] ?? null, $field, $zone);
            if ($value !== null) {
                $lines[] = '- ' . $this->label($field) . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * What an edit changes: one line per field whose value differs, from what it is to what it becomes.
     *
     * @param string $calendar display name of the calendar of the task
     * @param array<string, mixed> $plan the plan of an edit
     * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
     * @return string|null the body, or null when the plan carries no task to compare
     */
    private function edit(string $calendar, array $plan, DateTimeZone $zone): ?string {
        $before = $this->task($plan['before'] ?? null);
        $after = $this->task($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $summary = $this->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [Translator::t(
            'Editing the task %s in the calendar %s.',
            [$this->strong($summary), $this->emphasis($calendar)]
        )];

        return $this->changes($lines, $before, $after, $zone);
    }

    /**
     * What completing changes: the title of the task and the progress it reaches, with the moment it was done.
     *
     * @param string $calendar display name of the calendar of the task
     * @param array<string, mixed> $plan the plan of a completion
     * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
     * @return string|null the body, or null when the plan carries no task to complete
     */
    private function complete(string $calendar, array $plan, DateTimeZone $zone): ?string {
        $before = $this->task($plan['before'] ?? null);
        $after = $this->task($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $summary = $this->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [Translator::t(
            'Marking the task %s as completed in the calendar %s.',
            [$this->strong($summary), $this->emphasis($calendar)]
        )];
        $progress = $this->value($after['percentComplete'] ?? null, 'percentComplete', $zone);
        if ($progress !== null) {
            $lines[] = '- ' . Translator::t('Progress') . ': ' . $progress;
        }
        $completed = $this->value($after['completed'] ?? null, 'completed', $zone);
        if ($completed !== null) {
            $lines[] = '- ' . Translator::t('Completed at') . ': ' . $completed;
        }

        return implode("\n", $lines);
    }

    /**
     * What deleting means: the task by title, and that it goes to the calendar trash.
     *
     * @param string $calendar display name of the calendar of the task
     * @param array<string, mixed> $plan the plan of a delete
     * @return string|null the body, or null when the plan carries no task to name
     */
    private function delete(string $calendar, array $plan): ?string {
        $before = $this->task($plan['before'] ?? null);
        $summary = $before === null ? '' : $this->text($before['summary'] ?? '');
        if ($summary === '') {
            return null;
        }
        $lines = [Translator::t(
            'Moving the task %s to the trash of the calendar %s.',
            [$this->strong($summary), $this->emphasis($calendar)]
        )];
        $lines[] = Translator::t('The task stays in the calendar trash, where you can bring it back.');

        return implode("\n", $lines);
    }

    /**
     * One line per field whose value differs between the two states of the task.
     *
     * @param list<string> $lines the body so far
     * @param array<string, mixed> $before task as it is
     * @param array<string, mixed> $after task as it will be
     * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
     * @return string the body with the changed fields
     */
    private function changes(array $lines, array $before, array $after, DateTimeZone $zone): string {
        foreach (self::FIELDS as $field) {
            $from = $this->value($before[$field] ?? null, $field, $zone);
            $to = $this->value($after[$field] ?? null, $field, $zone);
            if ($from === $to) {
                continue;
            }
            $lines[] = '- ' . $this->label($field) . ': ' . ($from ?? Translator::t('empty'))
                . ' → ' . ($to ?? Translator::t('empty'));
        }

        return implode("\n", $lines);
    }

    /**
     * A write on somebody else's calendar affects other people, and the person must hear it.
     *
     * @param string $body body rendered so far
     * @param array<string, mixed> $plan the whole plan
     * @param string $calendar display name of the calendar, used when the notice names none
     * @return string the body with the notice of a shared calendar, if the plan carries one
     */
    private function shared(string $body, array $plan, string $calendar): string {
        foreach ((array) ($plan['shared'] ?? []) as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $owner = $this->text($notice['ownerDisplayName'] ?? $notice['owner'] ?? '');
            if ($owner === '') {
                continue;
            }
            $resource = $this->text($notice['resource'] ?? '') ?: $calendar;
            return $body . "\n" . Translator::t(
                'The calendar %s belongs to %s and is shared with you, so the change affects other people.',
                [$this->emphasis($resource), $this->strong($owner)]
            );
        }

        return $body;
    }

    /**
     * @param mixed $value raw plan value of a calendar
     * @return string|null the display name, or null when the plan carries none
     */
    private function name(mixed $value): ?string {
        if (!is_array($value)) {
            return null;
        }
        $name = $this->text($value['name'] ?? '');

        return $name === '' ? null : $name;
    }

    /**
     * @param mixed $value raw plan value of a task
     * @return array<string, mixed>|null the task fields, or null when the plan carries no task
     */
    private function task(mixed $value): ?array {
        return is_array($value) && $value !== [] ? $value : null;
    }

    /**
     * @param mixed $value raw plan value of one field
     * @param string $field key of the field, which decides how its value is read
     * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
     * @return string|null the value a person reads, or null when it is empty
     */
    private function value(mixed $value, string $field, DateTimeZone $zone): ?string {
        if ($field === 'percentComplete') {
            return is_numeric($value) ? $this->text($value) . '%' : null;
        }
        if ($field === 'priority') {
            return is_numeric($value) ? $this->priority((int) $value) : null;
        }
        if (in_array($field, ['start', 'due', 'completed'], true)) {
            return $this->date($value, $zone);
        }
        $text = $this->text($value);

        return $text === '' ? null : PlanText::inline($text, self::EXCERPT);
    }

    /**
     * A date of the task in the timezone of the reader: a due date must fall on the day they see.
     *
     * @param mixed $value raw plan value of a date field
     * @param DateTimeZone $zone timezone the reader is in
     * @return string|null the formatted date, or null when the field carries none
     */
    private function date(mixed $value, DateTimeZone $zone): ?string {
        $text = $this->text($value);
        if ($text === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($text);
        } catch (Exception) {
            return PlanText::inline($text, self::EXCERPT);
        }
        // A date without a time is a whole day for everybody; moving it to another zone would move the day.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }

        // The person's own language decides the order of day and month: "02/10" is two different days in two countries.
        $formatted = (new IntlDateFormatter(Translator::locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, $zone))->format($date);

        return is_string($formatted) ? $formatted : $date->setTimezone($zone)->format('Y-m-d H:i');
    }

    /** @param int $priority iCalendar priority, 0 undefined and 1 the highest */
    private function priority(int $priority): string {
        // One literal per call: the translation tool extracts the source texts from here.
        return match (true) {
            $priority <= 0 => Translator::t('without priority'),
            $priority === 1 => Translator::t('highest priority'),
            $priority <= 4 => Translator::t('high priority'),
            $priority === 5 => Translator::t('normal priority'),
            $priority <= 8 => Translator::t('low priority'),
            default => Translator::t('lowest priority'),
        };
    }

    /**
     * The timezone the plan carries, resolved once and reused for every date of the body.
     *
     * A plan built without an account behind it, or one naming a zone this PHP build does not know,
     * reads as UTC: the instant is right, only its hour is one the reader may not expect, which beats
     * failing to describe the write at all.
     *
     * @param array<string, mixed> $plan the whole plan
     * @return DateTimeZone timezone every date of this plan reads as
     */
    private function zone(array $plan): DateTimeZone {
        $name = $this->text($plan['timezone'] ?? '');
        if ($name === '') {
            return new DateTimeZone('UTC');
        }
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /** @param string $field key of a task field */
    private function label(string $field): string {
        // One literal per call: the translation tool extracts the source texts from here.
        return match ($field) {
            'summary' => Translator::t('Title'),
            'description' => Translator::t('Description'),
            'start' => Translator::t('Starts'),
            'due' => Translator::t('Due'),
            'priority' => Translator::t('Priority'),
            'percentComplete' => Translator::t('Progress'),
            'completed' => Translator::t('Completed at'),
            default => Translator::t('Title'),
        };
    }

    /** @param mixed $value raw plan value */
    private function text(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param string $text the name a person recognizes @return string the name in bold, inert as Markdown */
    private function strong(string $text): string {
        return PlanText::strong($text);
    }

    /** @param string $text the name of a collection, read as a name and not as an id @return string the name in italics, inert as Markdown */
    private function emphasis(string $text): string {
        return PlanText::em($text);
    }
}
