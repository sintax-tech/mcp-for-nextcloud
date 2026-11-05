<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup as ScopedCalendarAccess;
use OCA\Mcp\Platform\Dav\CalDavWrite as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDavObjects as CalendarStore;
use OCA\Mcp\Modules\Tasks\Writing\TaskSharedNotice as SharedGuard;
use OCA\Mcp\Modules\Tasks\Writing\TaskTrashPolicy as TrashPolicy;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema as Schema;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler;
use OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler;
use OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler;
use OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite;
use OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use RuntimeException;

/** VTODO tools independent of the optional Tasks UI app. */
final class TasksModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
	/**
	 * Receives the work classes while the legacy schemas remain unchanged.
	 *
	 * @param \OCA\Mcp\Modules\Tasks\Reading\ListTaskCalendarsHandler $listCalendars existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler $listTasks existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler $readTask existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler $createTask existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler $editTask existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskHandler $completeTask existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskHandler $deleteTask existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Creation\CreateTaskPreview $createPlans existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Editing\EditTaskPreview $editPlans existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskPreview $completePlans existing task work
	 * @param \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskPreview $deletePlans existing task work
	 * @param OutputReferenceFilter $references Files reference availability gate
	 */
	public function __construct(
		private \OCA\Mcp\Modules\Tasks\Reading\ListTaskCalendarsHandler $listCalendars,
		private \OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler $listTasks,
		private \OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler $readTask,
		private \OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler $createTask,
		private \OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler $editTask,
		private \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskHandler $completeTask,
		private \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskHandler $deleteTask,
		private \OCA\Mcp\Modules\Tasks\Creation\CreateTaskPreview $createPlans,
		private \OCA\Mcp\Modules\Tasks\Editing\EditTaskPreview $editPlans,
		private \OCA\Mcp\Modules\Tasks\Completion\CompleteTaskPreview $completePlans,
		private \OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskPreview $deletePlans,
		private OutputReferenceFilter $references,
	) {
	}

	/**
	 * Declares the tool schemas and their operations for the central write gate.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function definitions(): array {
		$calendar = Schema::calendar('VTODO calendar path from List task calendars.');
		$identity = ['calendar' => $calendar, 'uid' => Schema::uid()];
		$fields = [
			'summary' => Schema::text('Task title. Omit fields to preserve their existing values.', 1, 1024),
			'description' => Schema::text('Task description.'),
			'start' => [
				'type' => ['string', 'null'],
				'description' => 'ISO date or timestamp with offset; null clears the start.',
			],
			'due' => [
				'type' => ['string', 'null'],
				'description' => 'ISO date or timestamp with offset; null clears the due date. Must use the same date type as start.',
			],
			'priority' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 9,
				'description' => 'iCalendar priority: 0 undefined, 1 highest, 9 lowest.',
			],
			'percentComplete' => [
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 100,
				'description' => 'Progress percentage. 100 completes the task; a lower value reopens it.',
			],
			'categories' => [
				'type' => 'array',
				'maxItems' => \OCA\Mcp\Modules\Tasks\Composing\TaskData::MAX_CATEGORIES,
				'items' => ['type' => 'string', 'maxLength' => 1024],
				'description' => 'Free-text task categories, as in the Tasks app: 1-255 characters each after trimming spaces. '
					. 'The list replaces all existing categories; [] clears them; omit to preserve them.',
			],
			'parent' => [
				'type' => ['string', 'null'],
				'minLength' => 1,
				'maxLength' => 255,
				'description' => 'UID of the parent task, making this task its subtask as in the Tasks app. '
					. 'The parent must be in the same calendar; null makes the task top-level; omit to preserve it.',
			],
		];
		$definitions = [
			[
				'tasks_list_calendars',
				'List your own and shared calendars supporting tasks (VTODO).',
				'read',
				[],
				[],
			],
			[
				'tasks_list_tasks',
				'List tasks, including undated tasks, in one visible task calendar, optionally only those with a category '
					. 'or only the subtasks of one task.',
				'read',
				[
					'calendar' => $calendar,
					'include_completed' => [
						'type' => 'boolean',
						'default' => false,
						'description' => 'Include completed tasks.',
					],
					'category' => Schema::text(
						'Only list tasks with this category, compared exactly but ignoring case. '
							. 'Omit the field to apply no category filter; the value is not a pattern.',
						1,
						\OCA\Mcp\Modules\Tasks\Composing\TaskData::MAX_CATEGORY_LENGTH
					),
					'parent' => Schema::text(
						'Only list the direct subtasks of the task with this UID. Omit the field to list every task.',
						1,
						255
					),
					'limit' => Schema::limit(),
					'offset' => Schema::offset(),
				],
				['calendar'],
			],
			[
				'tasks_read_task',
				'Read a task by its UID in a visible task calendar.',
				'read',
				$identity,
				['calendar', 'uid'],
			],
			[
				'tasks_create_task',
				'Create a simple task in a writable task calendar.',
				'create',
				['calendar' => $calendar] + $fields,
				['calendar', 'summary'],
			],
			[
				'tasks_edit_task',
				'Edit supplied fields of a simple task, preserving other properties.',
				'edit',
				$identity + ['etag' => Schema::etag()] + $fields,
				['calendar', 'uid'],
			],
			[
				'tasks_complete_task',
				'Mark a simple task completed with 100 percent progress and a completion timestamp.',
				'edit',
				$identity + ['etag' => Schema::etag()],
				['calendar', 'uid'],
			],
			[
				'tasks_delete_task',
				'Move a simple task to the calendar trash. Refused when calendar retention is zero.',
				'delete',
				$identity + ['etag' => Schema::etag()],
				['calendar', 'uid'],
			],
		];
		return array_map(static fn ($definition) => Schema::definition('tasks', 'dav', ...$definition), $definitions);
	}

	/**
	 * Describes the confirmation workflow, data protection and recovery limitations.
	 *
	 * @return list<string>
	 */
	public function guideNotes(): array {
		return [
			'Task calendars support VTODO. These tools use core CalDAV and work even without the '
				. 'Tasks app; list task calendars before addressing a task by UID.',
			'Undated tasks are included. Completed tasks are hidden by default in listings. '
				. 'Recurring tasks can be read; changing recurring tasks or tasks with participants is '
				. 'refused to protect their series and scheduling.',
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

	/**
	 * Builds a real before/after plan without changing the DAV collection.
	 *
	 * @param string $name registered tool name
	 * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed>
	 * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
	 * @throws InvalidArgumentException when the tool or supplied fields are invalid
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		// Task output can carry ATTACH references to Files nodes: an unreadable Files scope is the same 503 before
		// any task is looked up, whether it exists or not.
		$this->references->assertAvailable($userId);
		return match ($name) {
			'tasks_create_task' => $this->createPlans->plan($arguments, $userId),
			'tasks_edit_task' => $this->editPlans->plan($arguments, $userId),
			'tasks_complete_task' => $this->completePlans->plan($arguments, $userId),
			'tasks_delete_task' => $this->deletePlans->plan($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

	/**
	 * Describes the plan as the text the person confirms, in the language of the request.
	 *
	 * Stateless on purpose: this module is a shared container service, so the timezone comes from the
	 * plan {@see self::preview()} built, not from anything this object remembers about the caller.
	 *
	 * @param string $name registered write tool name
	 * @param array<string, mixed> $plan the plan preview() returned for that tool
	 * @return string|null Markdown body, or null when this renderer has nothing to say about it
	 */
	public function renderPlan(string $name, array $plan): ?string {
		return (new \OCA\Mcp\Modules\Tasks\Presentation\TasksPlanRenderer())->render($name, $plan);
	}

	/**
	 * Reads data or executes a prepared write after confirmation and access checks.
	 *
	 * @param string $name registered tool name
	 * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
	 * @throws InvalidArgumentException when the tool or supplied fields are invalid
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function call(string $name, array $arguments, string $userId): array {
		$this->references->assertAvailable($userId);
		return match ($name) {
			'tasks_list_calendars' => $this->listCalendars->handle($arguments, $userId),
			'tasks_list_tasks' => $this->listTasks->handle($arguments, $userId),
			'tasks_read_task' => $this->readTask->handle($arguments, $userId),
			'tasks_create_task' => $this->createTask->handle($arguments, $userId),
			'tasks_edit_task' => $this->editTask->handle($arguments, $userId),
			'tasks_complete_task' => $this->completeTask->handle($arguments, $userId),
			'tasks_delete_task' => $this->deleteTask->handle($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}

}
