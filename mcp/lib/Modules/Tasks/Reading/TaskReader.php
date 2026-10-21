<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Tasks\Reading\TaskRepository;
final class TaskReader
{
    public function __construct(
        private CalendarAccess $access,
        private TaskRepository $repository,
        private \OCA\Mcp\Modules\Tasks\Presentation\TaskOutput $output,
    )
    {
    }
    public function execute(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolve($userId, $arguments['calendar'], 'VTODO');
        $row = $this->repository->row($calendar->id, $arguments['uid']);
        $parsed = $this->repository->readable($row, $calendar->ownedByOther($userId));
        return ToolResult::json($this->output->item($userId, $parsed) + ['etag' => $row['etag'], 'calendar' => $calendar->path]);
    }
}
