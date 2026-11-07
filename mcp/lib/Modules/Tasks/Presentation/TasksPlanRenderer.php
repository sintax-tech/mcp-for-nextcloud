<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

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
	private const FIELDS = ['summary', 'description', 'start', 'due', 'priority', 'percentComplete', 'categories', 'parent'];

	/** How the parents of the plan being rendered read, and whether they change. */
	private ?ParentPlanText $parents = null;

	/**
	 * @param string $tool write tool of this module
	 * @param array<string, mixed> $plan plan returned by \OCA\Mcp\Modules\Tasks\TasksModule::preview()
	 * @return string|null Markdown body, or null when the plan lacks what this renderer needs
	 */
	public function render(string $tool, array $plan): ?string {
		$calendar = (new TaskPlanValues())->name($plan['calendar'] ?? null);
		if ($calendar === null) {
			return null;
		}
		$zone = (new TaskPlanValues())->zone($plan);
		$this->parents = new ParentPlanText($plan['parents'] ?? null);
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
		$after = (new TaskPlanValues())->task($plan['after'] ?? null);
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

		return implode("\n", (new TaskStatusPlanText())->create($lines, $after, $zone));
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
		$before = (new TaskPlanValues())->task($plan['before'] ?? null);
		$after = (new TaskPlanValues())->task($plan['after'] ?? null);
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
		$before = (new TaskPlanValues())->task($plan['before'] ?? null);
		$after = (new TaskPlanValues())->task($plan['after'] ?? null);
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
		$before = (new TaskPlanValues())->task($plan['before'] ?? null);
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
			// Categories are compared as lists: two different lists may read alike once joined.
			$same = match ($field) {
				'categories' => $this->names($before[$field] ?? null) === $this->names($after[$field] ?? null),
				// Parents are compared by UID: two parents can read alike in the plan.
				'parent' => ParentPlanText::same($before[$field] ?? null, $after[$field] ?? null),
				default => $from === $to,
			};
			if ($same) {
				continue;
			}
			$lines[] = '- ' . $this->label($field) . ': ' . ($from ?? Translator::t('empty'))
				. ' → ' . ($to ?? Translator::t('empty'));
		}

		return implode("\n", (new TaskStatusPlanText())->edit($lines, $before, $after, $zone));
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
	 * @param mixed $value raw plan value of one field
	 * @param string $field key of the field, which decides how its value is read
	 * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
	 * @return string|null the value a person reads, or null when it is empty
	 */
	private function value(mixed $value, string $field, DateTimeZone $zone): ?string {
		return (new TaskPlanValues($this->parents))->value($value, $field, $zone);
	}

	/**
	 * @param mixed $value raw plan value of the categories field
	 * @return list<string> the non-empty category names, in order
	 */
	private function names(mixed $value): array {
		return (new TaskPlanValues())->names($value);
	}

	/**
	 * @param string $field key of a task field
	 * @return string translated label
	 */
	private function label(string $field): string {
		return (new TaskPlanValues())->label($field);
	}

	/**
	 * @param mixed $value raw plan value
	 * @return string trimmed scalar text
	 */
	private function text(mixed $value): string {
		return is_scalar($value) ? trim((string) $value) : '';
	}

	/**
	 * @param string $text the name a person recognizes
	 * @return string the name in bold, inert as Markdown
	 */
	private function strong(string $text): string {
		return PlanText::strong($text);
	}

	/**
	 * @param string $text the name of a collection, read as a name and not as an id
	 * @return string the name in italics, inert as Markdown
	 */
	private function emphasis(string $text): string {
		return PlanText::em($text);
	}
}
