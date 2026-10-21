<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Deletion;

use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult as ToolSchema;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
/**
 * calendar_delete_event: deletes a whole event series into the CalDAV trash, never permanently.
 *
 * The DELETE goes through the CalDAV pipeline with If-Match set to the ETag that was just read, so
 * the node carries the event to the trash exactly as it does when the user deletes it in the app.
 */
final class EventDeleter implements \OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation
{
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param SharedGuard $guard confirmation gate for calendars of somebody else
     * @param CalendarDav $dav the official write pipeline
     * @param CalendarStore $store calendar storage port, used to confirm the object reached the trash
     * @param EventRepository $events event lookup with classification and ETag checks
     * @param TrashPolicy $trash CalDAV trash retention
     * @param Scheduling $scheduling honest report of what was scheduled
     */
    public function __construct(
        private CalendarAccess $access,
        private SharedGuard $guard,
        private CalendarDav $dav,
        private CalendarStore $store,
        private EventRepository $events,
        private TrashPolicy $trash,
        private Scheduling $scheduling,
    )
    {
    }
    /**
     * @param array{calendar: string, uid: string, etag?: string, send_invitations?: bool, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, calendar, deleted, recoverable, scheduling}
     * @throws CalendarException when not writable, protected, changed meanwhile or the trash is disabled
     */
    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : $prepared->dispatch();
    }
    /**
     * Validates and constructs the write without dispatching, so the registry can show its plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite|array
    {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = ($arguments['confirm_shared'] ?? false) === true ? null : $this->guard->forCollection($calendar, $userId)) !== null) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($confirmation);
        }
        if (!$this->trash->recoverable()) {
            throw CalendarException::blocked(\OCA\Mcp\Modules\Calendar\Events\EventMessages::trashDisabled());
        }
        $stored = $this->events->forChange(
            $calendar,
            $arguments['uid'],
            $userId,
            $arguments['etag'] ?? null
        );
        $notify = (bool) ($arguments['send_invitations'] ?? false);
        return new \OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite(
            $calendar,
            null,
            $stored,
            null,
            function () use ($calendar, $stored, $notify, $userId, $arguments): array {
                $this->dav->delete(
                    $userId,
                    $calendar->uri,
                    $stored->uri,
                    $stored->etag,
                    $notify
                );
                // The re-read proves the node really went to the trash instead of being purged: the backend
                // keeps the row with a deleted-at timestamp under a renamed "-deleted" URI.
                if (!\OCA\Mcp\Platform\Dav\CalDav\TrashedObject::exists($this->store, $calendar->id, $stored->uri)) {
                    throw new \RuntimeException(\OCA\Mcp\Modules\Calendar\Events\EventMessages::DAV_FAILURE);
                }
                // There is no object left to read a SCHEDULE-STATUS from, so only the request is reported.
                return ToolSchema::json(
                    [
                        'uid' => $arguments['uid'],
                        'calendar' => $calendar->path,
                        'deleted' => true,
                        'recoverable' => true,
                        'scheduling' => $this->scheduling->report($notify, null),
                    ]
                );
            }
        );
    }
}
