<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Execution;

use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use RuntimeException;
use OCA\Mcp\Modules\Tasks\Content\TaskDocument;
final class TaskWriter
{
    public function __construct(
        private CalendarDav $dav,
        private CalendarStore $store,
        private TaskDocument $data,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
    )
    {
    }
    public function save(array $prepared, string $userId): array
    {
        $calendar = $prepared['calendar'];
        $row = $prepared['row'];
        $after = $prepared['after'];
        $uri = $row['uri'] ?? (string) $this->data->master($after)->UID . '.ics';
        if ($row === null) {
            $this->dav->put(
                $userId,
                $calendar->uri,
                $uri,
                $after->serialize(),
                false
            );
        } else {
            $this->dav->update(
                $userId,
                $calendar->uri,
                $uri,
                $row['etag'],
                $after->serialize(),
                false
            );
        }
        $written = $this->store->object($calendar->id, $uri) ?? throw new RuntimeException('Task read back failed');
        return ToolResult::json($this->output->item($userId, $this->data->parse($written['data'])) + ['etag' => $written['etag'], 'calendar' => $calendar->path]);
    }
}
