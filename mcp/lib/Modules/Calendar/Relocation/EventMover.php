<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Relocation;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\Relocation\EventRelocator;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
/**
 * calendar_move_event: moves an event between two writable calendars of the same owner.
 */
final class EventMover implements \OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation
{
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param EventRelocator $relocator shared move/transfer logic
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     */
    public function __construct(
        private CalendarAccess $access,
        private EventRelocator $relocator,
        private SharedGuard $guard,
    )
    {
    }
    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, etag, from, to}
     * @throws \OCA\Mcp\Modules\Calendar\Execution\CalendarException when the move is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : ToolSchema::json($prepared->dispatch());
    }
    /**
     * Builds the move proposal without dispatching it, so the registry can show the plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array
    {
        $source = $this->access->resolveWritable($userId, $arguments['calendar']);
        $target = $this->access->resolveWritable($userId, $arguments['targetCalendar']);
        // A move never crosses owners: when it would, relocate() still answers with the transfer
        // hint, and that answer needs no gate. Origin and destination are both gated, because
        // either side can be the shared calendar a valid move is about to touch.
        if ($source->ownerPrincipal === $target->ownerPrincipal) {
            $confirmation = (($arguments['confirm_shared'] ?? false) === true ? null : $this->guard->forCollection($source, $userId)) ?? (($arguments['confirm_shared'] ?? false) === true ? null : $this->guard->forCollection($target, $userId));
            if ($confirmation !== null) {
                return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($confirmation);
            }
        }
        return $this->relocator->prepare(
            $userId,
            $arguments['calendar'],
            $arguments['uid'],
            $arguments['targetCalendar'],
            $arguments['etag'] ?? null,
            false
        );
    }
}
