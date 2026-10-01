<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Tasks;
/** Component query port; rows are read through the existing CalendarStore. */
interface TaskStore {
    /** @return list<string> Live VTODO object URIs in one authorized calendar. */
    public function uris(int $calendarId): array;
}
