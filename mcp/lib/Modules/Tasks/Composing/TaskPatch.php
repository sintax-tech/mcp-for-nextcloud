<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Composing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use Sabre\VObject\Component\VTodo;
use Throwable;

/** Applies the existing task fields to a cloned master, preserving unrelated iCalendar properties. */
final class TaskPatch {
	/**
	 * Applies normalized categories, fields, dates, status, progress and write metadata.
	 *
	 * @param VTodo $task master of the cloned calendar
	 * @param array<string, mixed> $arguments validated write arguments
	 * @param DateTimeImmutable $now write clock
	 * @param bool $complete whether this is the complete-task operation
	 * @param list<string>|null $categories validated categories, null when omitted
	 * @return void
	 * @throws InvalidArgumentException when task fields or date dependencies are invalid
	 */
	public function apply(VTodo $task, array $arguments, DateTimeImmutable $now, bool $complete, ?array $categories): void {
		if ($categories !== null) {
			// The list replaces every CATEGORIES line; one multi-value line escapes commas inside a name.
			unset($task->CATEGORIES);
			if ($categories !== []) {
				$task->add('CATEGORIES', $categories);
			}
		}
		(new \OCA\Mcp\Modules\Tasks\Hierarchy\TaskRelations())->apply($task, $arguments);
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
		$dates = new TaskDateInput();
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
		} elseif (array_key_exists('status', $arguments)) {
			(new TaskStatus())->apply($task, $arguments['status'], $arguments['percentComplete'] ?? null, $stamp);
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
	}
}
