<?php
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
