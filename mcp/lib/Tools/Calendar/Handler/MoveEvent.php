<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRelocator;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;

/**
 * calendar_move_event: moves an event between two writable calendars of the same owner.
 */
final class MoveEvent implements CalendarTool {
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param EventRelocator $relocator shared move/transfer logic
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     */
    public function __construct(
        private CalendarAccess $access,
        private EventRelocator $relocator,
        private SharedGuard $guard,
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_move_event',
            'Move um evento para outro calendário do mesmo dono, sem sobrescrever.' . ToolSchema::NO_NOTIFICATION . SharedGuard::DESCRIPTION_SUFFIX,
            'move',
            ['calendar' => ToolSchema::calendar('path do calendário de origem'), 'uid' => ToolSchema::uid(), 'targetCalendar' => ToolSchema::calendar('path do calendário de destino'), 'etag' => ToolSchema::etag(), 'confirm_shared' => SharedGuard::property()],
            ['calendar', 'uid', 'targetCalendar'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, from, to}
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the move is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array {
        $source = $this->access->resolveWritable($userId, $arguments['calendar']);
        $target = $this->access->resolveWritable($userId, $arguments['targetCalendar']);
        // A move never crosses owners: when it would, relocate() still answers with the transfer
        // hint, and that answer needs no gate. Origin and destination are both gated, because
        // either side can be the shared calendar a valid move is about to touch.
        if ($source->ownerPrincipal === $target->ownerPrincipal) {
            $confirmation = $this->guard->confirm($source, $userId, $arguments)
                ?? $this->guard->confirm($target, $userId, $arguments);
            if ($confirmation !== null) {
                return $confirmation;
            }
        }
        return ToolSchema::result($this->relocator->relocate($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, false));
    }
}
