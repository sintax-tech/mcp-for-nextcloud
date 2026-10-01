<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

/** Public writes use prepare; selftest invokes execute directly under its own controlled script. */
interface CalendarWriteTool extends CalendarTool {
    /** @return PreparedCalendarWrite|array a validated proposal, or the internal SharedGuard response */
    public function prepare(array $arguments, string $userId): PreparedCalendarWrite|array;
}
