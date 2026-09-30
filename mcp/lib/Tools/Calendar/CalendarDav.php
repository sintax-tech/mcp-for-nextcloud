<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Writes CalDAV events through the official Nextcloud pipeline.
 *
 * Implementations must behave exactly as a CalDAV client (or the Calendar app) would: the same
 * ACL, the same iCalendar validation, the same scheduling rules, the same trash and the same sync
 * notifications. Nothing here writes to the database directly.
 */
interface CalendarDav {
    /**
     * Creates a calendar object, refusing to overwrite an existing one.
     *
     * @param string $userId authenticated UID
     * @param string $calendarUri calendar URI in the user's calendar home
     * @param string $objectUri new object URI
     * @param string $ics serialized iCalendar
     * @param bool $scheduling false sends `x-nc-scheduling: false`, so nothing is scheduled
     * @param int|null $sizeLimit maximum ICS size in bytes, read from dav/event_size_limit when null
     * @param list<int> $acceptedStatuses HTTP statuses that count as success
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the write
     */
    public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult;

    /**
     * Replaces a calendar object, always guarded by its current ETag.
     *
     * @param string $userId authenticated UID
     * @param string $calendarUri calendar URI in the user's calendar home
     * @param string $objectUri object URI
     * @param string $etag ETag read from the backend, sent as If-Match
     * @param string $ics serialized iCalendar
     * @param bool $scheduling false sends `x-nc-scheduling: false`, so nothing is scheduled
     * @param int|null $sizeLimit maximum ICS size in bytes, read from dav/event_size_limit when null
     * @param list<int> $acceptedStatuses HTTP statuses that count as success
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the write
     */
    public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult;

    /**
     * Deletes a calendar object into the CalDAV trash.
     *
     * @param string $userId authenticated UID
     * @param string $calendarUri calendar URI in the user's calendar home
     * @param string $objectUri object URI
     * @param string|null $etag ETag sent as If-Match, or null to skip the guard
     * @param bool $scheduling false sends `x-nc-scheduling: false`, so no CANCEL is scheduled
     * @param list<int> $acceptedStatuses HTTP statuses that count as success
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the deletion
     */
    public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult;

    /**
     * Moves a calendar object to another calendar of the same home, keeping its URI and never overwriting.
     *
     * @param string $userId authenticated UID
     * @param string $fromCalendarUri source calendar URI
     * @param string $objectUri object URI, unchanged by the move
     * @param string $toCalendarUri destination calendar URI
     * @param string|null $etag ETag sent as If-Match, or null to skip the guard
     * @param list<int> $acceptedStatuses HTTP statuses that count as success
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the move
     */
    public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult;

    /**
     * Creates a calendar collection. Only the selftest uses it; the write tools never create calendars.
     *
     * @param string $userId authenticated UID
     * @param string $calendarUri new calendar URI
     * @param string $displayName calendar display name
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the calendar
     */
    public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult;

    /**
     * Deletes a calendar collection. Only the selftest uses it.
     *
     * @param string $userId authenticated UID
     * @param string $calendarUri calendar URI
     * @return DavResult
     * @throws CalendarException when the pipeline refuses the deletion
     */
    public function deleteCalendar(string $userId, string $calendarUri): DavResult;
}