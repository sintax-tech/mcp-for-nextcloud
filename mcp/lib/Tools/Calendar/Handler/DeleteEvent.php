<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\Scheduling;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Tools\Calendar\TrashPolicy;

/**
 * calendar_delete_event: deletes a whole event series into the CalDAV trash, never permanently.
 *
 * The DELETE goes through the CalDAV pipeline with If-Match set to the ETag that was just read, so
 * the node carries the event to the trash exactly as it does when the user deletes it in the app.
 */
final class DeleteEvent implements \OCA\Mcp\Tools\Calendar\CalendarWriteTool {
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
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_delete_event',
            CalendarMessages::TOOL_DELETE_EVENT
            . CalendarMessages::SEND_INVITATIONS_NOTE . CalendarMessages::SHARED_CONFIRMATION_SUFFIX,
            'delete',
            [
                'calendar' => ToolSchema::calendar(),
                'uid' => ToolSchema::uid(),
                'etag' => ToolSchema::etag(),
                'send_invitations' => ToolSchema::sendInvitations(),
                'confirm_shared' => SharedGuard::property(),
            ],
            ['calendar', 'uid'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, etag?: string, send_invitations?: bool, confirm_shared?: bool} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, calendar, deleted, recoverable, scheduling}
     * @throws CalendarException when not writable, protected, changed meanwhile or the trash is disabled
     */
    public function execute(array $arguments, string $userId): array {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : $prepared->dispatch();
    }

    /**
     * Validates and constructs the write without dispatching, so the registry can show its plan first.
     *
     * @param array<string, mixed> $arguments raw tool arguments
     * @param string $userId authenticated user id
     * @return \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
     */
    public function prepare(array $arguments, string $userId): \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite|array {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (($confirmation = $this->guard->confirm($calendar, $userId, $arguments)) !== null) {
            return $confirmation;
        }
        if (!$this->trash->recoverable()) {
            throw CalendarException::blocked(CalendarMessages::trashDisabled());
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        $notify = (bool)($arguments['send_invitations'] ?? false);
        return new \OCA\Mcp\Tools\Calendar\PreparedCalendarWrite($calendar, null, $stored, null, function () use ($calendar, $stored, $notify, $userId, $arguments): array {
            $this->dav->delete($userId, $calendar->uri, $stored->uri, $stored->etag, $notify);
            // The re-read proves the node really went to the trash instead of being purged: the backend
            // keeps the row with a deleted-at timestamp (apps/dav/lib/CalDAV/CalDavBackend.php:1735-1745).
            $row = $this->store->object($calendar->id, $stored->uri);
            if ($row === null || !$row['deleted']) {
                throw new \RuntimeException(CalendarMessages::DAV_FAILURE);
            }
            // There is no object left to read a SCHEDULE-STATUS from, so only the request is reported.
            return ToolSchema::result([
                'uid' => $arguments['uid'],
                'calendar' => $calendar->path,
                'deleted' => true,
                'recoverable' => true,
                'scheduling' => $this->scheduling->report($notify, null),
            ]);
        });
    }
}
