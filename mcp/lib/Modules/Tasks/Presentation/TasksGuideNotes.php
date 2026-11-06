<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

/** Preserves the task workflow notes published by the legacy module. */
final class TasksGuideNotes {
	/** Static text provider only. */
	private function __construct() {
	}

	/** @return list<string> task behavior and confirmation notes in published order */
	public static function notes(): array {
		return [
			'Task calendars support VTODO. These tools use core CalDAV and work even without the '
				. 'Tasks app; list task calendars before addressing a task by UID.',
			'Undated tasks are included. Completed tasks are hidden by default in listings. '
				. 'Recurring tasks can be read; changing recurring tasks or tasks with participants is '
				. 'refused to protect their series and scheduling.',
			'Task status (NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED) can be set on create and edit, as in the Tasks app. '
				. 'Status alone: COMPLETED sets 100 percent and the completion time (preserving an existing time if already completed); '
				. 'IN-PROCESS clears the time and changes 100 to 99 and zero or absent progress to 1; NEEDS-ACTION clears the time and changes 100 to 99; '
				. 'CANCELLED keeps progress and the completion time and is still listed. Explicit percentComplete wins when consistent: '
				. 'COMPLETED requires 100, NEEDS-ACTION and IN-PROCESS reject 100, and CANCELLED accepts 0-100. '
				. 'With explicit status, zero progress does not add PERCENT-COMPLETE where absent. '
				. 'Progress alone still derives status and reopens a cancelled task. Omitting both preserves them. '
				. 'Create/edit plans show status changes and the addition, removal or change of the completion date in the plan timezone.',
			'Task categories are free text, as in the Tasks app. On create and edit, a categories list '
				. 'replaces all existing categories, [] clears them and omitting the field preserves them. '
				. 'tasks_list_tasks can filter by one category, ignoring case.',
			'Subtasks link to their parent by UID (RELATED-TO), as in the Tasks app, and every task returns '
				. 'its parent UID or null. On create and edit, parent sets it, null makes the task top-level and '
				. 'omitting it preserves it; the parent must be a visible task of the same calendar and cannot be '
				. 'the task or one of its subtasks, and no task may end up deeper than 64 levels, the moved subtasks '
				. 'included. tasks_list_tasks with parent lists the direct subtasks. Deleting or completing a parent '
				. 'does not change its subtasks. The hierarchy is checked on the state read when the plan is built and '
				. 'again at confirmation; confirmed MCP writes that set a parent in one calendar run one at a time (they '
				. 'are refused when the file locking of the server is disabled), but '
				. 'other clients can still change related tasks at the same moment, and an answer with a warning says '
				. 'that the parent chain then loops.',
			'Private and confidential tasks in shared calendars are hidden. Writable shares '
				. 'require confirm_shared=true after showing the owner notice.',
			'Every write starts with a before/after plan. Wait for explicit approval before '
				. 'confirm=true; the optional etag checks for concurrent edits. Writing grants start '
				. 'disabled.',
			'Deletion uses the native calendar trash and is refused when calendar retention is '
				. 'zero. The result confirms recovery through the calendar trash.',
		];
	}
}
