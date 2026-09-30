<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRelocator;
use OCA\Mcp\Tools\Calendar\ToolSchema;

/**
 * calendar_move_event: moves an event between two writable calendars of the same owner.
 */
final class MoveEvent implements CalendarTool {
    /**
     * @param EventRelocator $relocator shared move/transfer logic
     */
    public function __construct(private EventRelocator $relocator) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_move_event',
            'Move um evento para outro calendário do mesmo dono, sem sobrescrever.' . ToolSchema::NO_NOTIFICATION,
            'move',
            ['calendar' => ToolSchema::calendar('path do calendário de origem'), 'uid' => ToolSchema::uid(), 'targetCalendar' => ToolSchema::calendar('path do calendário de destino'), 'etag' => ToolSchema::etag()],
            ['calendar', 'uid', 'targetCalendar'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, from, to}
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the move is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array {
        return ToolSchema::result($this->relocator->relocate($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, false));
    }
}
