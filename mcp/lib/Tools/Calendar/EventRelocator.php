<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;

/**
 * Moves an event between two calendars the user can write, keeping its UID and object URI.
 *
 * Move stays within one owner; transfer crosses owners. Both refuse to overwrite.
 *
 * The MOVE goes through the CalDAV pipeline with `Overwrite: F` and `If-Match`, so the destination
 * calendar's ACL decides, the CalDavBackend keeps the object id and fires its own events, and the
 * sync tokens of both sides advance. Neither mode ever schedules a message: the Sabre schedule
 * plugin returns early on a MOVE (sabre/dav/lib/CalDAV/Schedule/Plugin.php:371-377) and nothing
 * listens to `afterMove`, which is also how the Calendar app moves an event
 * (`src/store/calendarObjects.js:43-65`).
 */
class EventRelocator {
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param CalendarStore $store calendar storage port, used to confirm the move landed
     * @param CalendarDav $dav the official write pipeline
     * @param EventRepository $events event lookup with classification and ETag checks
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private CalendarDav $dav,
        private EventRepository $events,
    ) {}

    /**
     * @param string $userId acting user
     * @param string $sourcePath source calendar path
     * @param string $uid event UID
     * @param string $targetPath target calendar path
     * @param string|null $etag expected ETag
     * @param bool $crossOwner true for transfer (owners must differ), false for move (same owner)
     * @return array{uid:string, from:string, to:string, participantsNotified:bool, note:string}
     * @throws CalendarException when a calendar is not writable, the mode does not match the owners,
     *                           the event is protected or changed, the target already has it,
     *                           or a transfer would carry the guests to another owner
     */
    public function relocate(string $userId, string $sourcePath, string $uid, string $targetPath, ?string $etag, bool $crossOwner): array {
        $source = $this->access->resolveWritable($userId, $sourcePath);
        $target = $this->access->resolveWritable($userId, $targetPath);
        if ($source->id === $target->id) {
            throw CalendarException::conflict(CalendarMessages::CONFLICT_SAME_CALENDAR);
        }
        $sameOwner = $source->ownerPrincipal === $target->ownerPrincipal;
        if (!$crossOwner && !$sameOwner) {
            throw CalendarException::blocked(CalendarMessages::MOVE_NEEDS_TRANSFER);
        }
        if ($crossOwner && $sameOwner) {
            throw CalendarException::blocked(CalendarMessages::TRANSFER_NEEDS_MOVE);
        }
        $stored = $this->events->forChange($source, $uid, $userId, $etag);
        if ($crossOwner && $this->hasAttendees($stored->vcalendar)) {
            throw CalendarException::blocked(CalendarMessages::TRANSFER_WITH_ATTENDEES);
        }
        $this->events->assertFree($target, $uid, $stored->uri);
        $this->dav->move($userId, $source->uri, $stored->uri, $target->uri, $stored->etag);
        // Tree::move falls back to copy plus delete when the target declines moveInto
        // (sabre/dav/lib/DAV/Tree.php:163-187), and that fallback swallows exceptions
        // (apps/dav/lib/CalDAV/Calendar.php:416-432), so the 201 alone proves nothing.
        if ($this->store->objectByUid($target->id, $uid) === null || $this->store->objectByUid($source->id, $uid) !== null) {
            throw new \RuntimeException(CalendarMessages::MOVE_NOT_CONFIRMED);
        }
        return [
            'uid' => $uid,
            'from' => $source->path,
            'to' => $target->path,
            'participantsNotified' => false,
            'note' => CalendarMessages::PARTICIPANTS_NOT_NOTIFIED,
        ];
    }

    /**
     * @param VCalendar $calendar series including every detached override
     * @return bool whether the event carries any guest at all
     */
    private function hasAttendees(VCalendar $calendar): bool {
        foreach ($calendar->select('VEVENT') as $event) {
            if (count($event->select('ATTENDEE')) > 0) { return true; }
        }
        return false;
    }
}