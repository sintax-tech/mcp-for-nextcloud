<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use Psr\Container\ContainerExceptionInterface;

/** Component query port; rows are read through the existing CalendarStore. */
interface TaskStore {
    /**
     * Queries live VTODO object URIs, including tasks without dates.
     *
     * @param int $calendarId ID of an authorized VTODO calendar
     * @return list<string>
     * @throws ContainerExceptionInterface when the core backend cannot be resolved
     */
    public function uris(int $calendarId): array;
}
