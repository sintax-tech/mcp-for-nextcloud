<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Calendar\ScopedCalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Common\OutputReferenceFilter;
use OCA\Mcp\Tools\Dav\CollectionSchema as Schema;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\Tasks\Handler\CreateTaskHandler;
use OCA\Mcp\Tools\Tasks\Handler\EditTaskHandler;
use OCA\Mcp\Tools\Tasks\Handler\ListTasksHandler;
use OCA\Mcp\Tools\Tasks\Handler\ParentTaskWrite;
use OCA\Mcp\Tools\Tasks\Handler\ReadTaskHandler;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use RuntimeException;

/** VTODO tools independent of the optional Tasks UI app. */
final class TasksModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
    /**
     * Receives VTODO queries, calendar access, native CalDAV writes and retention policy.
     *
     * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
     * @param CalendarStore $store read-only storage port
     * @param TaskStore $tasks live VTODO query port
     * @param CalendarDav $dav native DAV write port
     * @param TaskData $data complete serialized DAV object
     * @param SharedGuard $guard shared-owner confirmation policy
     * @param TrashPolicy $trash calendar retention policy
     * @param ITimeFactory $time clock for timestamps
     * @param OutputReferenceFilter $references output rule for ATTACH references to Files nodes
     * @param UserTimezone|null $zones timezone of the account, so a plan shows the dates the user reads
     * @param ILockingProvider|null $locks serializes confirmed MCP writes that set a parent (TaskHierarchyLock); without
     *     a provider that excludes, those writes are refused
     * @return void
     */
    public function __construct(
        private ScopedCalendarAccess $access,
        private CalendarStore $store,
        private TaskStore $tasks,
        private CalendarDav $dav,
        private TaskData $data,
        private SharedGuard $guard,
        private TrashPolicy $trash,
        private ITimeFactory $time,
        private OutputReferenceFilter $references,
        private ?UserTimezone $zones = null,
        private ?ILockingProvider $locks = null,
    ) {}

    /**
     * Declares the tool schemas and their operations for the central write gate.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array {
        $calendar = ToolSchema::calendar('VTODO calendar path from List task calendars.');
        $identity = ['calendar' => $calendar, 'uid' => ToolSchema::uid()];
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
                'maxItems' => TaskData::MAX_CATEGORIES,
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
                        TaskData::MAX_CATEGORY_LENGTH
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
                $identity + ['etag' => ToolSchema::etag()] + $fields,
                ['calendar', 'uid'],
            ],
            [
                'tasks_complete_task',
                'Mark a simple task completed with 100 percent progress and a completion timestamp.',
                'edit',
                $identity + ['etag' => ToolSchema::etag()],
                ['calendar', 'uid'],
            ],
            [
                'tasks_delete_task',
                'Move a simple task to the calendar trash. Refused when calendar retention is zero.',
                'delete',
                $identity + ['etag' => ToolSchema::etag()],
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
            CreateTaskHandler::TOOL => (new CreateTaskHandler($this->parentWrite()))->preview($arguments, $userId),
            EditTaskHandler::TOOL => (new EditTaskHandler($this->parentWrite()))->preview($arguments, $userId),
            default => $this->flow()->prepare($name, $arguments, $userId)['plan'],
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
        return (new TasksPlanRenderer())->render($name, $plan);
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
        if ($name === 'tasks_list_calendars') {
            return ToolResult::json(
                array_map(static fn ($calendar) => (array) $calendar, $this->access->visible($userId, 'VTODO'))
            );
        }
        return match ($name) {
            'tasks_list_tasks' => (new ListTasksHandler($this->access, $this->store, $this->tasks, $this->data, new TaskRowReader($this->data)))->handle($arguments, $userId),
            ReadTaskHandler::TOOL => (new ReadTaskHandler($this->access, $this->objects()))->handle($arguments, $userId),
            CreateTaskHandler::TOOL => (new CreateTaskHandler($this->parentWrite()))->handle($arguments, $userId),
            EditTaskHandler::TOOL => (new EditTaskHandler($this->parentWrite()))->handle($arguments, $userId),
            default => $this->flow()->run($name, $arguments, $userId),
        };
    }

    /** @return TaskObjects lookup, classification and output of task objects */
    private function objects(): TaskObjects {
        return new TaskObjects($this->store, $this->data, $this->references);
    }

    /** @return TaskWriteFlow plan and native write of the task writes */
    private function flow(): TaskWriteFlow {
        return new TaskWriteFlow($this->access, $this->store, $this->dav, $this->data, $this->objects(), $this->guard, $this->trash, $this->time, $this->zones);
    }

    /** @return ParentTaskWrite create and edit with the parent check, lock and warning */
    private function parentWrite(): ParentTaskWrite {
        return new ParentTaskWrite($this->flow(), new TaskParentStep(
            new TaskHierarchy($this->store, $this->tasks, $this->data),
            new ParentTitles($this->store, $this->data),
            new TaskRelations(),
            $this->data,
            $this->locks === null ? null : new TaskHierarchyLock($this->locks),
        ));
    }
}
