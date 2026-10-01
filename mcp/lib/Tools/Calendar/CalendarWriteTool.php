<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

/** Public writes use prepare; selftest invokes execute directly under its own controlled script. */
interface CalendarWriteTool extends CalendarTool {
    /**
     * Validates the write and builds its proposal without dispatching, allowing the registry to present a plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} the prepared write, or a SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): PreparedCalendarWrite|array;
}
