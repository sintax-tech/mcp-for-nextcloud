<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks\Handler;

use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\ScopedCalendarAccess;
use OCA\Mcp\Tools\Tasks\CategoryFilterNote;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskRowReader;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/**
 * Backs `tasks_list_tasks`.
 *
 * Scans the live tasks of one visible calendar in small chunks, hides what the share protects, applies
 * the completed, category and parent filters before the offset is counted, and cuts one page. A category filter
 * without a single match carries a note so the empty page is not read as an empty calendar.
 */
final class ListTasksHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tasks_list_tasks';

	/** Page size when the caller sends no limit. */
	private const DEFAULT_LIMIT = 50;

	/** Number of objects fetched from the store per query. */
	private const CHUNK = 100;

	/**
	 * Receives the calendar access, the object store, the live query port and the task serializer.
	 *
	 * @param ScopedCalendarAccess $access the calendars the tool may reach: native visibility and ACL, narrowed by the resource scope
	 * @param CalendarStore $store read-only storage port
	 * @param TaskStore $tasks live VTODO query port
	 * @param TaskData $data complete serialized DAV object
	 * @param TaskRowReader $reader visibility rule of stored task objects
	 * @param CategoryFilterNote $note explanation of a category filter that matched nothing
	 * @return void
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private CalendarStore $store,
		private TaskStore $tasks,
		private TaskData $data,
		private TaskRowReader $reader,
		private CategoryFilterNote $note = new CategoryFilterNote(),
	) {
	}

	/**
	 * Lists one page of tasks of a visible task calendar.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: calendar, include_completed, category, parent, limit, offset
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws CalendarException when the calendar path is foreign, malformed, not visible or outside the scope
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	public function handle(array $arguments, string $userId): array {
		$calendar = $this->access->resolve($userId, $arguments['calendar'], 'VTODO');
		$items = [];
		$more = false;
		$limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
		$offset = $arguments['offset'] ?? 0;
		$matched = 0;
		// Counted apart, so a parent filter that empties the page is not reported as a category miss.
		$categoryMatched = 0;
		// Fetch objects in small chunks, retaining the CalDAV query's live-object scope.
		foreach (array_chunk($this->tasks->uris($calendar->id), self::CHUNK) as $uris) {
			foreach ($this->store->objects($calendar->id, $uris) as $row) {
				try {
					$parsed = $this->reader->readable($row, $calendar->ownedByOther($userId));
					$item = $this->data->item($parsed);
				} catch (ToolFailure) {
					continue;
				}
				if (!($arguments['include_completed'] ?? false) && $item['status'] === 'COMPLETED') {
					continue;
				}
				// Filter before counting the offset, so pages are taken from the matching tasks only.
				if (isset($arguments['category']) && !$this->data->hasCategory($item, $arguments['category'])) {
					continue;
				}
				$categoryMatched++;
				if (isset($arguments['parent']) && $item['parent'] !== $arguments['parent']) {
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
		$page = [
			'tasks' => $items,
			'hasMore' => $more,
			'nextOffset' => $more ? $offset + count($items) : null,
		];
		// A filter without a single match reads exactly like an empty calendar; the note tells them apart.
		if (isset($arguments['category']) && $categoryMatched === 0) {
			$page['note'] = $this->note->forMiss($arguments['category'], ($arguments['include_completed'] ?? false) === true);
		}
		return ToolResult::json($page);
	}
}
