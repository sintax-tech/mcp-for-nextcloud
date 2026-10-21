<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Reading;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
final class TaskCalendarReader
{
    public function __construct(private CalendarAccess $access)
    {
    }
    public function execute(array $arguments, string $userId): array
    {
        return ToolResult::json(array_map(static fn($calendar) => (array) $calendar, $this->access->visible($userId, 'VTODO')));
    }
}
