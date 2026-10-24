<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\ScopedCalendarAccess;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashedObject;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;
use OCP\AppFramework\Utility\ITimeFactory;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;

/**
 * The plan and the native write of the four task write tools, moved out of TasksModule without changing what they
 * check: grant-resolved writable calendar, visibility, simple task, optional ETag, trash retention, shared-owner
 * notice; then a CalDAV PUT, update or trash with the read ETag as If-Match, and a read-back.
 *
 * The create and edit handlers add the parent check to the prepared plan; complete and delete run it as it is.
 */
final class TaskWriteFlow {
	/**
	 * Receives everything a task write touches.
	 *
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 * @param CalendarStore $store read-only storage port
	 * @param CalendarDav $dav native DAV write port
	 * @param TaskData $data complete serialized DAV object
	 * @param TaskObjects $objects lookup, classification and output of task objects
	 * @param SharedGuard $guard shared-owner confirmation policy
	 * @param TrashPolicy $trash calendar retention policy
	 * @param ITimeFactory $time clock for timestamps
	 * @param UserTimezone|null $zones timezone of the account, so a plan shows the dates the user reads
	 * @return void
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private CalendarStore $store,
		private CalendarDav $dav,
		private TaskData $data,
		private TaskObjects $objects,
		private SharedGuard $guard,
		private TrashPolicy $trash,
		private ITimeFactory $time,
		private ?UserTimezone $zones = null,
	) {
	}

	/**
	 * The writable task calendar the arguments address, resolved as prepare() resolves it.
	 *
	 * @param string $userId authenticated user UID
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return Calendar the calendar
	 * @throws ToolFailure when the calendar is not visible, writable or inside the scope
	 */
	public function writableCalendar(string $userId, array $arguments): Calendar {
		return $this->access->resolveWritable($userId, $arguments['calendar'], 'VTODO');
	}

	/**
	 * Previews or runs one write tool without anything added by a handler: complete and delete.
	 *
	 * @param string $name write tool name
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
	 * @throws InvalidArgumentException when the tool or supplied fields are invalid
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function run(string $name, array $arguments, string $userId): array {
		$prepared = $this->prepare($name, $arguments, $userId);
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($prepared['plan']);
		}
		return ToolResult::json($this->commit($prepared, $arguments, $userId));
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
	public function prepare(string $name, array $arguments, string $userId): array {
		if (!in_array($name, ['tasks_create_task', 'tasks_edit_task', 'tasks_complete_task', 'tasks_delete_task'], true)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		if ($name === 'tasks_edit_task' && array_intersect(
			array_keys($arguments),
			['summary', 'description', 'priority', 'start', 'due', 'percentComplete', 'categories', 'parent']
		) === []) {
			throw new InvalidArgumentException(Translator::t('Provide at least one field to change.'));
		}
		$calendar = $this->writableCalendar($userId, $arguments);
		$deleting = $name === 'tasks_delete_task';
		if ($deleting && !$this->trash->recoverable()) {
			throw new ToolFailure(Translator::t('Calendar trash is disabled; the task cannot be deleted safely.'));
		}
		$row = null;
		$before = null;
		$now = $this->time->now();
		if ($name === 'tasks_create_task') {
			$after = $this->data->create($arguments, $now);
		} else {
			$row = $this->objects->row($calendar->id, $arguments['uid']);
			$old = $this->objects->readable($row, $calendar->ownedByOther($userId));
			$before = $this->objects->output($userId, $old);
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
			'calendar' => (array)$calendar,
			'etag' => $row['etag'] ?? null,
			'before' => $before,
			// A new task has no uid the person could use yet: the confirmed call generates a random one and returns it.
			// The iCalendar blob carries that provisional uid too, so a creation plan leaves the blob out.
			// $after stays the object written to DAV; only its shown copy loses a refused ATTACH, as $before does.
			'after' => $after === null ? null : array_diff_key($this->objects->output($userId, $after), $name === 'tasks_create_task' ? ['uid' => 1, 'icalendar' => 1] : []),
			'shared' => $shared,
			'recoverable' => $deleting,
			'consequence' => $deleting ? Translator::t('The task will be moved to the calendar trash.') : null,
			// The timezone travels with the plan, so whoever renders it later reads the dates of the
			// account that asked, without this object having to remember who that was.
			'timezone' => $this->zones?->forUser($userId)->getName(),
		];
		return ['calendar' => $calendar, 'row' => $row, 'after' => $after, 'plan' => $plan];
	}

	/**
	 * Executes a prepared write after confirmation: the shared notice, then the native trash, PUT or update.
	 *
	 * @param array{calendar:Calendar, row:?array, after:?VCalendar, plan:array<string, mixed>} $prepared result of prepare()
	 * @param array<string, mixed> $arguments validated tool arguments, confirm included
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the answer: the written task, or the trash confirmation
	 * @throws ToolFailure when the shared notice is not confirmed or the trash is disabled
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function commit(array $prepared, array $arguments, string $userId): array {
		if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			throw new ToolFailure(Translator::t('Confirm the change to the shared collection with confirm_shared=true.'));
		}
		$calendar = $prepared['calendar'];
		$row = $prepared['row'];
		$after = $prepared['after'];
		if ($after === null) {
			// Check again immediately before dispatch; no permanent deletion is allowed for tasks.
			if (!$this->trash->recoverable()) {
				throw new ToolFailure(Translator::t('Calendar trash is disabled; the task cannot be deleted safely.'));
			}
			$this->dav->delete($userId, $calendar->uri, $row['uri'], $row['etag'], false);
			if (!TrashedObject::exists($this->store, $calendar->id, $row['uri'])) {
				throw new RuntimeException('Task trash verification failed');
			}
			return ['uid' => $arguments['uid'], 'calendar' => $calendar->path, 'deleted' => true, 'recoverable' => true];
		}
		$uri = $row['uri'] ?? (string)$this->data->master($after)->UID . '.ics';
		if ($row === null) {
			$this->dav->put($userId, $calendar->uri, $uri, $after->serialize(), false);
		} else {
			$this->dav->update($userId, $calendar->uri, $uri, $row['etag'], $after->serialize(), false);
		}
		$written = $this->store->object($calendar->id, $uri) ?? throw new RuntimeException('Task read back failed');
		return $this->objects->output($userId, $this->data->parse($written['data'])) + ['etag' => $written['etag'], 'calendar' => $calendar->path];
	}
}
