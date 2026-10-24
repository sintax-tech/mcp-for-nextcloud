<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\ToolFailure;
use Sabre\VObject\Component\VCalendar;

/**
 * Checks a new parent link before a task write: the parent is a task the caller may read in the same calendar, the
 * link closes no loop, and no task ends up deeper than {@see self::MAX_DEPTH} levels.
 *
 * The ceiling is what the MCP writes observe, counted from a top-level task (level 1): the deepest task of the
 * subtree being moved, the task itself for a new one, must stay at or above level 64. It is checked on the state
 * read now; other CalDAV clients can still create deeper or looping hierarchies, which the MCP tools then read
 * without trouble (every walk is bounded) and refuse to nest under until they are fixed.
 *
 * Only the direct parent is read with the caller's rights. Its ancestors and the descendants of the moved task are
 * read by UID inside the same authorized calendar to count levels and find loops, and nothing of them reaches the
 * answer: every refusal is one of the fixed messages of {@see TaskHierarchyMessages}.
 */
final class TaskHierarchy {
	/** Deepest level a task may reach through an MCP write; a top-level task is level 1. */
	public const MAX_DEPTH = 64;

	/** Objects fetched from the store per query when the subtree of a moved task is measured. */
	private const CHUNK = 100;

	/**
	 * Receives the stores the hierarchy is read from.
	 *
	 * @param CalendarStore $store read-only storage port
	 * @param TaskStore $tasks live VTODO query port, for the descendants of a moved task
	 * @param TaskData $data parser of the stored objects
	 * @param TaskRelations $relations reader of parent links
	 * @return void
	 */
	public function __construct(
		private CalendarStore $store,
		private TaskStore $tasks,
		private TaskData $data,
		private TaskRelations $relations = new TaskRelations(),
	) {
	}

	/**
	 * Refuses a parent that cannot be used for the task.
	 *
	 * @param Calendar $calendar authorized writable calendar of the task
	 * @param string $userId authenticated user UID
	 * @param string $parent UID of the requested parent
	 * @param string $uid UID of the task being written
	 * @param bool $existing whether the task already exists and may carry subtasks of its own
	 * @return void
	 * @throws ToolFailure when the parent is not usable, closes a loop, sits above a broken hierarchy or the write
	 *     would exceed the ceiling
	 */
	public function assertParent(Calendar $calendar, string $userId, string $parent, string $uid, bool $existing): void {
		$current = $this->readableParent($calendar, $userId, $parent);
		$level = $this->level($calendar, $current, $uid);
		$height = $existing ? $this->height($calendar->id, $uid) : 1;
		if ($level + $height > self::MAX_DEPTH) {
			throw new ToolFailure(TaskHierarchyMessages::tooDeep(self::MAX_DEPTH));
		}
	}

	/**
	 * Whether the parent chain of a task, read again now, comes back to the task.
	 *
	 * @param int $calendarId authorized calendar ID
	 * @param string $uid UID of the task
	 * @return bool true when a loop runs through the task
	 */
	public function loopsThrough(int $calendarId, string $uid): bool {
		$next = $uid;
		for ($step = 0; $step <= self::MAX_DEPTH; $step++) {
			$calendar = $this->stored($calendarId, $next);
			if ($calendar === null) {
				return false;
			}
			$next = $this->relations->parent($this->data->master($calendar));
			if ($next === null) {
				return false;
			}
			if ($next === $uid) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The requested parent, read with the caller's rights.
	 *
	 * @param Calendar $calendar authorized calendar of the task
	 * @param string $userId authenticated user UID
	 * @param string $parent UID of the requested parent
	 * @return VCalendar the parent task
	 * @throws ToolFailure when the parent is missing, trashed, protected, malformed or in another calendar
	 */
	private function readableParent(Calendar $calendar, string $userId, string $parent): VCalendar {
		$row = $this->store->objectByUid($calendar->id, $parent, 'VTODO');
		if ($row === null) {
			throw new ToolFailure(TaskHierarchyMessages::parentNotFound());
		}
		try {
			return (new TaskRowReader($this->data))->readable($row, $calendar->ownedByOther($userId));
		} catch (ToolFailure) {
			throw new ToolFailure(TaskHierarchyMessages::parentNotFound());
		}
	}

	/**
	 * The level of the parent, walking its ancestors: a missing or trashed ancestor ends the chain, as the Tasks app
	 * then shows the task at the top; a loop or an unreadable ancestor makes the level unknown.
	 *
	 * @param Calendar $calendar authorized calendar of the task
	 * @param VCalendar $parent the requested parent
	 * @param string $uid UID of the task being written
	 * @return int level of the parent, 1 for a top-level parent
	 * @throws ToolFailure when the chain reaches the task, loops, cannot be read or is deeper than the ceiling
	 */
	private function level(Calendar $calendar, VCalendar $parent, string $uid): int {
		$seen = [(string)$this->data->master($parent)->UID => true];
		$current = $parent;
		for ($level = 1; $level <= self::MAX_DEPTH; $level++) {
			$next = $this->relations->parent($this->data->master($current));
			if ($next === null) {
				return $level;
			}
			if ($next === $uid) {
				throw new ToolFailure(TaskHierarchyMessages::loop());
			}
			if (isset($seen[$next])) {
				throw new ToolFailure(TaskHierarchyMessages::brokenHierarchy());
			}
			$seen[$next] = true;
			$row = $this->store->objectByUid($calendar->id, $next, 'VTODO');
			if ($row === null || $row['deleted']) {
				return $level;
			}
			try {
				$current = $this->data->parse($row['data']);
			} catch (ToolFailure) {
				throw new ToolFailure(TaskHierarchyMessages::brokenHierarchy());
			}
		}
		throw new ToolFailure(TaskHierarchyMessages::tooDeep(self::MAX_DEPTH));
	}

	/**
	 * The height of the subtree of an existing task, the task included: 1 for a task without subtasks. Live tasks
	 * of the calendar that cannot be parsed are not counted, as no client can show them as subtasks either.
	 *
	 * @param int $calendarId authorized calendar ID
	 * @param string $uid UID of the task being moved
	 * @return int levels of the subtree, at most one past the ceiling
	 */
	private function height(int $calendarId, string $uid): int {
		$children = [];
		foreach (array_chunk($this->tasks->uris($calendarId), self::CHUNK) as $uris) {
			foreach ($this->store->objects($calendarId, $uris) as $row) {
				if ($row['deleted'] ?? false) {
					continue;
				}
				try {
					$task = $this->data->master($this->data->parse($row['data']));
				} catch (ToolFailure) {
					continue;
				}
				$parent = $this->relations->parent($task);
				if ($parent !== null) {
					$children[$parent][] = (string)$task->UID;
				}
			}
		}
		$height = 1;
		$seen = [$uid => true];
		$frontier = [$uid];
		while ($frontier !== [] && $height <= self::MAX_DEPTH) {
			$next = [];
			foreach ($frontier as $node) {
				foreach ($children[$node] ?? [] as $child) {
					if (!isset($seen[$child])) {
						$seen[$child] = true;
						$next[] = $child;
					}
				}
			}
			if ($next === []) {
				break;
			}
			$height++;
			$frontier = $next;
		}
		return $height;
	}

	/**
	 * A live task of the calendar by UID, parsed, whoever may read it.
	 *
	 * @param int $calendarId authorized calendar ID
	 * @param string $uid UID of the task
	 * @return VCalendar|null the task, or null when it is missing, trashed or malformed
	 */
	private function stored(int $calendarId, string $uid): ?VCalendar {
		$row = $this->store->objectByUid($calendarId, $uid, 'VTODO');
		if ($row === null || $row['deleted']) {
			return null;
		}
		try {
			return $this->data->parse($row['data']);
		} catch (ToolFailure) {
			return null;
		}
	}
}
