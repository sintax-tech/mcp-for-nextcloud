<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Composing;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Reader;
use Throwable;

/** Reads VTODO and patches a simple task without discarding unrelated iCalendar properties. */
class TaskData {
	/** Most categories one task write accepts, as many as the tool schema allows. */
	public const MAX_CATEGORIES = 50;

	/** Longest category, in characters after trimming. */
	public const MAX_CATEGORY_LENGTH = 255;

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
		// Refuse invalid categories before cloning or preparing a write.
		$categories = array_key_exists('categories', $arguments) ? $this->validCategories($arguments['categories']) : null;
		$calendar = clone $original;
		(new TaskPatch())->apply($this->master($calendar), $arguments, $now, $complete, $categories);
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
			'parent' => (new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskRelations())->parent($task),
			'recurring' => isset($task->RRULE) || isset($task->RDATE) || count($calendar->select('VTODO')) > 1,
			'icalendar' => $calendar->serialize(),
		];
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
