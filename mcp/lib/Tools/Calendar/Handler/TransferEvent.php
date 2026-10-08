<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRelocator;
use OCA\Mcp\Tools\Calendar\ToolSchema;

/**
 * calendar_transfer_event: moves an event to a calendar of another owner that the user can write.
 */
final class TransferEvent implements \OCA\Mcp\Tools\Calendar\CalendarWriteTool {
    /**
     * @param EventRelocator $relocator shared move/transfer logic
     */
    public function __construct(private EventRelocator $relocator) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_transfer_event',
            CalendarMessages::TOOL_TRANSFER_EVENT . CalendarMessages::PARTICIPANTS_NOT_NOTIFIED,
            'transfer',
            [
                'calendar' => ToolSchema::calendar(CalendarMessages::propertyDescription('source-calendar')),
                'uid' => ToolSchema::uid(),
                'targetCalendar' => ToolSchema::calendar(CalendarMessages::propertyDescription('transfer-calendar')),
                'etag' => ToolSchema::etag(),
            ],
            ['calendar', 'uid', 'targetCalendar'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, etag, from, to}
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the transfer is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : ToolSchema::result($prepared->dispatch());
    }

    /**
     * Builds the transfer proposal without dispatching it, so the registry can show the plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite|array {
        return $this->relocator->prepare($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, true);
    }
}
