<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * One calendar tool: its registry definition and its handler.
 */
interface CalendarTool {
    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array;

    /**
     * Runs the tool for the authenticated user; arguments were already validated against the schema.
     *
     * @param array<string, mixed> $arguments tool arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
     * @throws CalendarException on a user-facing failure
     * @throws CalendarArgumentException on an argument the schema cannot validate
     */
    public function execute(array $arguments, string $userId): array;
}
