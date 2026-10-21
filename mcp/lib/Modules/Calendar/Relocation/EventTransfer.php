<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Relocation;

use OCA\Mcp\Modules\Calendar\Relocation\EventRelocator;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
/**
 * calendar_transfer_event: moves an event to a calendar of another owner that the user can write.
 */
final class EventTransfer implements \OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation
{
    /**
     * @param EventRelocator $relocator shared move/transfer logic
     */
    public function __construct(private EventRelocator $relocator)
    {
    }
    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, etag, from, to}
     * @throws \OCA\Mcp\Modules\Calendar\Execution\CalendarException when the transfer is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : ToolSchema::json($prepared->dispatch());
    }
    /**
     * Builds the transfer proposal without dispatching it, so the registry can show the plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array
    {
        return $this->relocator->prepare(
            $userId,
            $arguments['calendar'],
            $arguments['uid'],
            $arguments['targetCalendar'],
            $arguments['etag'] ?? null,
            true
        );
    }
}
