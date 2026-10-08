<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Service\Calendar;

use OCA\Mcp\Tools\Calendar\Calendar;

/** Read-only evidence that ordinary tool results do not expose. */
interface CalendarSelftestReader {
    /** @return array{syncToken:string, deleted:bool}|null fresh backend state, including trash */
    public function calendarState(Calendar $calendar): ?array;

    /** @return list<array{uri:string, data:string, etag:string}> the user's CalDAV inbox */
    public function schedulingObjects(string $uid): array;
}
