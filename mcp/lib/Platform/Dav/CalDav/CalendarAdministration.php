<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

use OCA\Mcp\Platform\Dav\Transport\DavResult;
interface CalendarAdministration
{
    public function mkcalendar(
        string $userId,
        string $calendarUri,
        string $displayName,
    ): DavResult;
    public function deleteCalendar(string $userId, string $calendarUri): DavResult;
}
