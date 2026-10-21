<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;
use OCA\Mcp\Modules\Tasks\Content\TaskDocument;
use OCA\Mcp\Modules\Tasks\Content\TaskPolicy;
final class TaskRepository
{
    public function __construct(
        private CalendarStore $store,
        private TaskDocument $data,
        private TaskPolicy $policy,
    )
    {
    }
    public function row(int $id, string $uid): array
    {
        return $this->store->objectByUid($id, $uid, 'VTODO') ?? throw new ToolFailure(FileAccessMessages::notFound());
    }
    public function readable(array $row, bool $shared): VCalendar
    {
        if ($row['deleted']) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        $parsed = $this->data->parse($row['data']);
        if (!$this->policy->visible($parsed, $shared)) {
            throw new ToolFailure(FileAccessMessages::notFound());
        }
        return $parsed;
    }
}
