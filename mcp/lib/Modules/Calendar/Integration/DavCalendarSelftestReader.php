<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Integration;

use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
/** Only reads stable33 CalDavBackend; every mutation belongs to CalendarDav. */
final class DavCalendarSelftestReader implements CalendarSelftestReader
{
    /** @param \Closure(): CalDavBackend $backendProvider resolves DAV only when reading */
    public function __construct(private \Closure $backendProvider)
    {
    }
    /** @inheritDoc */
    public function calendarState(Calendar $calendar): ?array
    {
        $row = ($this->backendProvider)()->getCalendarById($calendar->id);
        return $row === null ? null : [
            'syncToken' => (string) $row['{http://sabredav.org/ns}sync-token'],
            'deleted' => ($row['{http://nextcloud.com/ns}deleted-at'] ?? null) !== null,
        ];
    }
    /** @inheritDoc */
    public function schedulingObjects(string $uid): array
    {
        return array_map(
            static fn(array $row): array => ['uri' => (string) $row['uri'], 'data' => (string) $row['calendardata'], 'etag' => (string) $row['etag']],
            ($this->backendProvider)()->getSchedulingObjects('principals/users/' . $uid)
        );
    }
}
