<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Tasks\Integration;

use OCA\Mcp\Modules\Tasks\Reading\TaskQuery as TaskStore;
use Psr\Container\ContainerExceptionInterface;
/** Queries the core CalDAV backend lazily without requiring the optional Tasks app. */
class DavTaskQuery implements TaskStore
{
    /**
     * Receives the container without resolving the core CalDAV backend yet.
     *
     * @param \Closure $backendProvider lazy native backend provider
     * @return void
     */
    public function __construct(private \Closure $backendProvider)
    {
    }
    /**
     * Queries live VTODO object URIs, including tasks without dates.
     *
     * @param int $calendarId ID of an authorized VTODO calendar
     * @return list<string>
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function uris(int $calendarId): array
    {
        // Sabre component query includes undated tasks; an event time window would miss them.
        return array_values(
            ($this->backendProvider)()->calendarQuery(
                $calendarId,
                [
                    'name' => 'VCALENDAR',
                    'is-not-defined' => false,
                    'time-range' => null,
                    'prop-filters' => [],
                    'comp-filters' => [
                        [
                            'name' => 'VTODO',
                            'is-not-defined' => false,
                            'time-range' => null,
                            'prop-filters' => [],
                            'comp-filters' => [],
                        ],
                    ],
                ]
            )
        );
    }
}
