<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Modules\Tasks\Reading\TaskQuery as TaskStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Tasks\Content\TaskCategories;
use OCA\Mcp\Modules\Tasks\Reading\TaskRepository;
final class TaskLister
{
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private TaskStore $tasks,
        private TaskRepository $repository,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
        private TaskCategories $categories,
    )
    {
    }
    public function execute(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolve($userId, $arguments['calendar'], 'VTODO');
        $items = [];
        $more = false;
        $limit = $arguments['limit'] ?? 50;
        $offset = $arguments['offset'] ?? 0;
        $matched = 0;
        // Fetch objects in small chunks, retaining the CalDAV query's live-object scope.
        foreach (array_chunk($this->tasks->uris($calendar->id), 100) as $uris) {
            foreach ($this->store->objects($calendar->id, $uris) as $row) {
                try {
                    $parsed = $this->repository->readable($row, $calendar->ownedByOther($userId));
                    $item = $this->output->item($userId, $parsed);
                } catch (ToolFailure) {
                    continue;
                }
                if (!($arguments['include_completed'] ?? false) && $item['status'] === 'COMPLETED') {
                    continue;
                }
                // Filter before counting the offset, so pages are taken from the matching tasks only.
                if (isset($arguments['category']) && !$this->categories->hasCategory($item, $arguments['category'])) {
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
}
