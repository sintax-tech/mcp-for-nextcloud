<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Access\Visibility\OutputReferenceFilter;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup as ScopedCalendarAccess;
use OCA\Mcp\Modules\Tasks\Locating\TaskObjects;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `tasks_read_task`: one task of a visible task calendar, with its parent UID among the fields, the ETag and
 * the calendar path. A trashed, malformed or protected task reads exactly like a missing one.
 */
final class ReadTaskHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tasks_read_task';

	/**
	 * Receives the calendar access and the task objects.
	 *
	 * @param ScopedCalendarAccess $access the calendars the tool may reach: native visibility and ACL, narrowed by the resource scope
	 * @param TaskObjects $objects lookup, classification and output of task objects
	 * @return void
	 * @param OutputReferenceFilter $references availability gate before any calendar or task lookup
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private TaskObjects $objects,
		private OutputReferenceFilter $references,
	) {
	}

	/**
	 * Reads one task.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: calendar, uid
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when the calendar path is foreign, malformed, not visible or outside the scope
	 * @throws ToolFailure when the task is missing, trashed, malformed or protected
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	public function handle(array $arguments, string $userId): array {
		$this->references->assertAvailable($userId);
		$calendar = $this->access->resolve($userId, $arguments['calendar'], 'VTODO');
		$row = $this->objects->row($calendar->id, $arguments['uid']);
		$parsed = $this->objects->readable($row, $calendar->ownedByOther($userId));
		return ToolResult::json($this->objects->output($userId, $parsed) + ['etag' => $row['etag'], 'calendar' => $calendar->path]);
	}
}
