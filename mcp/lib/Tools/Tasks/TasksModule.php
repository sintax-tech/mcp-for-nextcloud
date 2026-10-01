<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Tools\Calendar\TrashedObject;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Dav\CollectionSchema as Schema;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;

/** VTODO tools independent of the optional Tasks UI app. */
final class TasksModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
    /**
     * Receives VTODO queries, calendar access, native CalDAV writes and retention policy.
     *
     * @param CalendarAccess $access collection visibility and write-access resolver
     * @param CalendarStore $store read-only storage port
     * @param TaskStore $tasks live VTODO query port
     * @param CalendarDav $dav native DAV write port
     * @param TaskData $data complete serialized DAV object
     * @param SharedGuard $guard shared-owner confirmation policy
     * @param TrashPolicy $trash calendar retention policy
     * @param ITimeFactory $time clock for timestamps
     * @param UserTimezone|null $zones timezone of the account, so a plan shows the dates the user reads
     * @return void
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private TaskStore $tasks,
        private CalendarDav $dav,
        private TaskData $data,
        private SharedGuard $guard,
        private TrashPolicy $trash,
        private ITimeFactory $time,
        private ?UserTimezone $zones = null,
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
                'List tasks, including undated tasks, in one visible task calendar.',
                'read',
                [
                    'calendar' => $calendar,
                    'include_completed' => [
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Include completed tasks.',
                    ],
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
        return $this->prepare($name, $arguments, $userId)['plan'];
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
        if ($name === 'tasks_list_calendars') {
            return ToolResult::json(
                array_map(static fn ($calendar) => (array) $calendar, $this->access->visible($userId, 'VTODO'))
            );
        }
        if ($name === 'tasks_list_tasks' || $name === 'tasks_read_task') {
            $calendar = $this->access->resolve($userId, $arguments['calendar'], 'VTODO');
            if ($name === 'tasks_read_task') {
                $row = $this->row($calendar->id, $arguments['uid']);
                $parsed = $this->readable($row, $calendar->ownedByOther($userId));
                return ToolResult::json(
                    $this->data->item($parsed) + ['etag' => $row['etag'], 'calendar' => $calendar->path]
                );
            }
            $items = [];
            $more = false;
            $limit = $arguments['limit'] ?? 50;
            $offset = $arguments['offset'] ?? 0;
            $matched = 0;
            // Fetch objects in small chunks, retaining the CalDAV query's live-object scope.
            foreach (array_chunk($this->tasks->uris($calendar->id), 100) as $uris) {
                foreach ($this->store->objects($calendar->id, $uris) as $row) {
                    try {
                        $parsed = $this->readable($row, $calendar->ownedByOther($userId));
                        $item = $this->data->item($parsed);
                    } catch (ToolFailure) {
                        continue;
                    }
                    if (!($arguments['include_completed'] ?? false) && $item['status'] === 'COMPLETED') {
                        continue;
                    }
                    if ($matched++ < $offset) {
                        continue;
                    }
                    if (count($items) >= $limit) {
                        $more = true;
                        break 2;
                    }
                    unset($item['icalendar']);
                    $items[] = $item + ['etag' => $row['etag'], 'calendar' => $calendar->path];
                }
            }
            return ToolResult::json(
                [
                    'tasks' => $items,
                    'hasMore' => $more,
                    'nextOffset' => $more ? $offset + count($items) : null,
                ]
            );
        }
        $prepared = $this->prepare($name, $arguments, $userId);
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($prepared['plan']);
        }
        if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(
                Translator::t('Confirm the change to the shared collection with confirm_shared=true.')
            );
        }
        $calendar = $prepared['calendar'];
        $row = $prepared['row'];
        $after = $prepared['after'];
        if ($after === null) {
            // Check again immediately before dispatch; no permanent deletion is allowed for tasks.
            if (!$this->trash->recoverable()) {
                throw new ToolFailure(
                    Translator::t('Calendar trash is disabled; the task cannot be deleted safely.')
                );
            }
            $this->dav->delete($userId, $calendar->uri, $row['uri'], $row['etag'], false);
            if (!TrashedObject::exists($this->store, $calendar->id, $row['uri'])) {
                throw new RuntimeException('Task trash verification failed');
            }
            return ToolResult::json(
                [
                    'uid' => $arguments['uid'],
                    'calendar' => $calendar->path,
                    'deleted' => true,
                    'recoverable' => true,
                ]
            );
        }
        $uri = $row['uri'] ?? (string) $this->data->master($after)->UID . '.ics';
        if ($row === null) {
            $this->dav->put($userId, $calendar->uri, $uri, $after->serialize(), false);
        } else {
            $this->dav->update($userId, $calendar->uri, $uri, $row['etag'], $after->serialize(), false);
        }
        $written = $this->store->object($calendar->id, $uri) ?? throw new RuntimeException('Task read back failed');
        return ToolResult::json(
            $this->data->item($this->data->parse($written['data'])) + ['etag' => $written['etag'], 'calendar' => $calendar->path]
        );
    }

    /**
     * Prepares the task plan, rechecking visibility, supported writes, ETag and retention.
     *
     * @param string $name write tool name
     * @param array<string, mixed> $arguments validated tool arguments
     * @param string $userId authenticated UID
     * @return array{calendar:Calendar, row:?array, after:?VCalendar, plan:array<string, mixed>}
     * @throws ToolFailure when access, task complexity, ETag or retention checks fail
     * @throws InvalidArgumentException when the tool or task fields are invalid
     */
    private function prepare(string $name, array $arguments, string $userId): array {
        if (!in_array(
            $name,
            [
                'tasks_create_task',
                'tasks_edit_task',
                'tasks_complete_task',
                'tasks_delete_task',
            ],
            true
        )) {
            throw new InvalidArgumentException('Unknown tool');
        }
        if ($name === 'tasks_edit_task' && array_intersect(
            array_keys($arguments),
            ['summary', 'description', 'priority', 'start', 'due', 'percentComplete']
        ) === []) {
            throw new InvalidArgumentException(Translator::t('Provide at least one field to change.'));
        }
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
        $deleting = $name === 'tasks_delete_task';
        if ($deleting && !$this->trash->recoverable()) {
            throw new ToolFailure(
                Translator::t('Calendar trash is disabled; the task cannot be deleted safely.')
            );
        }
        $row = null;
        $before = null;
        $now = $this->time->now();
        if ($name === 'tasks_create_task') {
            $after = $this->data->create($arguments, $now);
        } else {
            $row = $this->row($calendar->id, $arguments['uid']);
            $old = $this->readable($row, $calendar->ownedByOther($userId));
            $before = $this->data->item($old);
            $this->data->assertSimple($old);
            if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
                throw new ToolFailure(CommonMessages::conflict());
            }
            $after = $deleting ? null : $this->data->patch($old, $arguments, $now, $name === 'tasks_complete_task');
        }
        $notice = $this->guard->confirm($calendar, $userId, []);
        $shared = $notice === null ? [] : [json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)];
        $plan = [
            'requiresConfirmation' => true,
            'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
            'action' => $name,
            'calendar' => (array) $calendar,
            'etag' => $row['etag'] ?? null,
            'before' => $before,
            // A new task has no uid the person could use yet: the confirmed call generates a random one and returns it.
            'after' => $after === null ? null : array_diff_key($this->data->item($after), $name === 'tasks_create_task' ? ['uid' => 1] : []),
            'shared' => $shared,
            'recoverable' => $deleting,
            'consequence' => $deleting ? Translator::t('The task will be moved to the calendar trash.') : null,
            // The timezone travels with the plan, so whoever renders it later reads the dates of the
            // account that asked, without this module having to remember who that was.
            'timezone' => $this->zones?->forUser($userId)->getName(),
        ];
        return compact('calendar', 'row', 'after', 'plan');
    }

    /**
     * Reads a task object by UID within the already-authorized calendar.
     *
     * @param int $id authorized calendar ID
     * @param string $uid task UID
     * @return array<string, mixed>
     * @throws ToolFailure when no task object has that UID
     */
    private function row(int $id, string $uid): array {
        return $this->store->objectByUid($id, $uid) ?? throw new ToolFailure(CommonMessages::notFound());
    }

    /**
     * Parses a live task and checks classification before exposing any fields.
     *
     * @param array<string, mixed> $row object returned by CalendarStore
     * @param bool $shared whether the calendar belongs to another user
     * @return VCalendar
     * @throws ToolFailure when the object is trashed, malformed or protected in a share
     */
    private function readable(array $row, bool $shared): VCalendar {
        if ($row['deleted']) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        $parsed = $this->data->parse($row['data']);
        if (!$this->data->visible($parsed, $shared)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        return $parsed;
    }
}
