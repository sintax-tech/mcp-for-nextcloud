<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTimeImmutable;

/**
 * Storage port for CalDAV calendars and calendar objects.
 *
 * Implementations translate 1:1 to the Nextcloud CalDAV backend and hold no business rules:
 * visibility, ACL, classification, trash and conflict handling live in the tested classes.
 */
interface CalendarStore {
    /**
     * Lists the calendars owned by or shared with a principal.
     *
     * @param string $principalUri e.g. "principals/users/alice"
     * @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}>
     */
    public function calendarsForPrincipal(string $principalUri): array;

    /**
     * Returns the object URIs with a VEVENT overlapping the time range.
     *
     * @param int $calendarId backend calendar id
     * @param DateTimeImmutable $from inclusive start
     * @param DateTimeImmutable $to exclusive end
     * @return list<string>
     */
    public function eventUrisInRange(int $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Loads several live (not trashed) objects of one calendar.
     *
     * @param int $calendarId backend calendar id
     * @param list<string> $uris object URIs
     * @return list<array{id:int, uri:string, etag:string, data:string, deleted:bool}>
     */
    public function objects(int $calendarId, array $uris): array;

    /**
     * Loads one object by URI, including a trashed one (flagged by "deleted").
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null
     */
    public function object(int $calendarId, string $uri): ?array;

    /**
     * Finds the live object holding a UID in one calendar.
     *
     * @param int $calendarId backend calendar id
     * @param string $uid iCalendar UID
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null
     */
    public function objectByUid(int $calendarId, string $uid): ?array;

    /**
     * Creates an object.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri new object URI
     * @param string $data serialized iCalendar
     * @return string new ETag
     */
    public function create(int $calendarId, string $uri, string $data): string;

    /**
     * Replaces an object's data.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     * @param string $data serialized iCalendar
     * @return string new ETag
     */
    public function update(int $calendarId, string $uri, string $data): string;

    /**
     * Moves an object to another calendar, keeping its URI.
     *
     * @param string $sourceOwnerPrincipal owner principal of the source calendar
     * @param int $objectId backend object id
     * @param string $targetOwnerPrincipal owner principal of the target calendar
     * @param int $targetCalendarId backend calendar id of the target
     * @param string $uri object URI in the target
     * @return bool false when the backend could not complete the move
     */
    public function move(string $sourceOwnerPrincipal, int $objectId, string $targetOwnerPrincipal, int $targetCalendarId, string $uri): bool;

    /**
     * Deletes an object; the backend moves it to the CalDAV trash unless retention is disabled.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     */
    public function delete(int $calendarId, string $uri): void;
}
