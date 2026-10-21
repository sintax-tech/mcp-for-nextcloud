<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
/**
 * calendar_list_calendars: the event calendars the user owns or has been shared.
 */
final class CalendarReader
{
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     */
    public function __construct(private CalendarAccess $access)
    {
    }
    /**
     * @param array<string, mixed> $arguments no arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} list of {name, path, owner, writable}
     */
    public function execute(array $arguments, string $userId): array
    {
        $out = [];
        foreach ($this->access->visible($userId) as $calendar) {
            $out[] = [
                'name' => $calendar->name,
                'path' => $calendar->path,
                'owner' => $calendar->ownerId,
                'writable' => $calendar->writable,
            ];
        }
        return ToolSchema::json($out);
    }
}
