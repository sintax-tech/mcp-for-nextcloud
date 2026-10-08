<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/** Queries the core CalDAV backend lazily without requiring the optional Tasks app. */
class DavTaskStore implements TaskStore {
    /**
     * Receives the container without resolving the core CalDAV backend yet.
     *
     * @param ContainerInterface $container container resolving the core backend lazily
     * @return void
     */
    public function __construct(private ContainerInterface $container) {}

    /**
     * Queries live VTODO object URIs, including tasks without dates.
     *
     * @param int $calendarId ID of an authorized VTODO calendar
     * @return list<string>
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function uris(int $calendarId): array {
        // Sabre component query includes undated tasks; an event time window would miss them.
        return array_values(
            $this->container->get(CalDavBackend::class)->calendarQuery(
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
