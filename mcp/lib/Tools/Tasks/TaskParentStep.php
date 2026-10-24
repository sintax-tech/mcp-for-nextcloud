<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use Closure;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\WriteGate;

/**
 * What the `parent` argument adds to a create or an edit: the hierarchy check and the parent titles in the plan,
 * the hierarchy lock around a confirmed write that sets a parent, and a warning when the chain read back after the
 * write loops through the task (a concurrent change no lock of this app covers).
 *
 * The check runs in the preview and again in the confirmed call, on the state read at that moment; omitting
 * `parent` or sending null never reads the hierarchy, so a task can always be made top-level.
 */
final class TaskParentStep {
	/**
	 * Receives the hierarchy rules, the title lookup and the lock.
	 *
	 * @param TaskHierarchy $hierarchy parent check and loop detection
	 * @param ParentTitles $titles titles of the parents a plan names
	 * @param TaskRelations $relations reader of parent links
	 * @param TaskData $data parser of the prepared task
	 * @param TaskHierarchyLock|null $lock serializes confirmed MCP writes that set a parent; without it such a write is
	 *     refused, as when the server's locking is disabled
	 * @return void
	 */
	public function __construct(
		private TaskHierarchy $hierarchy,
		private ParentTitles $titles,
		private TaskRelations $relations,
		private TaskData $data,
		private ?TaskHierarchyLock $lock = null,
	) {
	}

	/**
	 * Checks the requested parent and names the parents in the plan.
	 *
	 * @param array{calendar:Calendar, row:?array, after:?\Sabre\VObject\Component\VCalendar, plan:array<string, mixed>} $prepared result of TaskWriteFlow::prepare()
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @param bool $existing whether the task already exists (edit) and may carry subtasks
	 * @return array{calendar:Calendar, row:?array, after:?\Sabre\VObject\Component\VCalendar, plan:array<string, mixed>} the prepared write, its plan with `parents`
	 * @throws ToolFailure when the parent cannot be used
	 */
	public function plan(array $prepared, array $arguments, string $userId, bool $existing): array {
		$after = $prepared['after'];
		$uid = $after === null ? null : (string)$this->data->master($after)->UID;
		if (($arguments['parent'] ?? null) !== null && $uid !== null) {
			$this->hierarchy->assertParent($prepared['calendar'], $userId, $arguments['parent'], $uid, $existing);
		}
		$prepared['plan']['parents'] = $this->titles->of($prepared['calendar'], $userId, [
			$prepared['plan']['before']['parent'] ?? null,
			$after === null ? null : $this->relations->parent($this->data->master($after)),
		]);
		return $prepared;
	}

	/**
	 * Runs a confirmed write under the hierarchy lock when it sets a parent; any other write runs as it is. Without a
	 * lock that excludes, a write that sets a parent is refused before anything runs.
	 *
	 * @template T
	 * @param Calendar $calendar writable calendar the arguments address
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param Closure(): T $write the prepare, check and write of the confirmed call
	 * @return T what the write returned
	 * @throws ToolFailure when another MCP request is changing the subtasks of the calendar, or no lock can serialize
	 *     the write
	 */
	public function guarded(Calendar $calendar, array $arguments, Closure $write): mixed {
		if (($arguments['parent'] ?? null) === null || !WriteGate::confirmed($arguments)) {
			return $write();
		}
		if ($this->lock === null) {
			throw new ToolFailure(TaskHierarchyMessages::lockingUnavailable());
		}
		return $this->lock->run($calendar->id, $write);
	}

	/**
	 * Adds a warning to the answer of a write that set a parent when the chain read back now loops through the task.
	 *
	 * @param array<string, mixed> $answer the written task, as TaskWriteFlow::commit() returned it
	 * @param Calendar $calendar calendar of the task
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the answer, with `warning` when a loop runs through the task
	 */
	public function afterWrite(array $answer, Calendar $calendar, array $arguments): array {
		if (($arguments['parent'] ?? null) === null || !isset($answer['uid'])) {
			return $answer;
		}
		if ($this->hierarchy->loopsThrough($calendar->id, (string)$answer['uid'])) {
			$answer['warning'] = TaskHierarchyMessages::loopAfterWrite();
		}
		return $answer;
	}
}
