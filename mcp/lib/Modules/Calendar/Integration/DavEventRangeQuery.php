<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Integration;

use DateTime;
use DateTimeImmutable;
use OCA\Mcp\Modules\Calendar\Reading\EventRangeQuery;
class DavEventRangeQuery implements EventRangeQuery
{
    /** @param \Closure(): \OCA\DAV\CalDAV\CalDavBackend $backendProvider */
    public function __construct(private \Closure $backendProvider)
    {
    }
    public function eventUrisInRange(
        int $calendarId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array
    {
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => ['start' => DateTime::createFromImmutable($from), 'end' => DateTime::createFromImmutable($to)],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];
        return array_values(array_map('strval', ($this->backendProvider)()->calendarQuery($calendarId, $filters)));
    }
}
