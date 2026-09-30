<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTime;
use DateTimeImmutable;
use OCA\DAV\CalDAV\CalDavBackend;

/**
 * Thin adapter from CalendarStore to OCA\DAV\CalDAV\CalDavBackend (nextcloud/server stable33).
 *
 * Only translates arguments and result shapes; it deliberately holds no business rule.
 * Line numbers refer to apps/dav/lib/CalDAV/CalDavBackend.php on branch stable33.
 */
class DavCalendarStore implements CalendarStore {
    /** Row key of the owner principal (OCA\DAV\DAV\Sharing\Plugin::NS_OWNCLOUD). */
    private const OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
    /** Row key set only on calendars shared with the principal. */
    private const READ_ONLY = '{http://owncloud.org/ns}read-only';
    /** Row key of the trash timestamp (OCA\DAV\DAV\Sharing\Plugin::NS_NEXTCLOUD). */
    private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';
    /** Row key of the supported component set. */
    private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

    /**
     * @param CalDavBackend $backend the DAV app's CalDAV backend
     */
    public function __construct(private CalDavBackend $backend) {}

    /**
     * Assumes `public function getCalendarsForUser($principalUri)` (line 320), which returns owned
     * and shared calendars; shared rows carry the "_shared_by_<owner>" URI and the read-only flag.
     *
     * @param string $principalUri e.g. "principals/users/alice"
     * @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}>
     */
    public function calendarsForPrincipal(string $principalUri): array {
        $out = [];
        foreach ($this->backend->getCalendarsForUser($principalUri) as $row) {
            $components = $row[self::COMPONENTS] ?? null;
            $out[] = [
                'id' => (int)$row['id'],
                'uri' => (string)$row['uri'],
                'displayName' => (string)($row['{DAV:}displayname'] ?? $row['uri']),
                'ownerPrincipal' => (string)($row[self::OWNER_PRINCIPAL] ?? $row['principaluri']),
                'readOnly' => (bool)($row[self::READ_ONLY] ?? false),
                'components' => $components === null ? [] : array_values($components->getValue()),
                'deleted' => ($row[self::DELETED_AT] ?? null) !== null,
            ];
        }
        return $out;
    }

    /**
     * Assumes `public function calendarQuery($calendarId, array $filters, $calendarType = self::CALENDAR_TYPE_CALENDAR):array`
     * (line 1919) with the Sabre CalendarQueryParser filter shape.
     *
     * @param int $calendarId backend calendar id
     * @param DateTimeImmutable $from inclusive start
     * @param DateTimeImmutable $to exclusive end
     * @return list<string>
     */
    public function eventUrisInRange(int $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array {
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [[
                'name' => 'VEVENT',
                'comp-filters' => [],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => ['start' => DateTime::createFromImmutable($from), 'end' => DateTime::createFromImmutable($to)],
            ]],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];
        return array_values(array_map('strval', $this->backend->calendarQuery($calendarId, $filters)));
    }

    /**
     * Assumes `public function getMultipleCalendarObjects($calendarId, array $uris, $calendarType = self::CALENDAR_TYPE_CALENDAR):array`
     * (line 1451), which already skips trashed objects.
     *
     * @param int $calendarId backend calendar id
     * @param list<string> $uris object URIs
     * @return list<array{id:int, uri:string, etag:string, data:string, deleted:bool}>
     */
    public function objects(int $calendarId, array $uris): array {
        if ($uris === []) {
            return [];
        }
        return array_values(array_map([$this, 'objectRow'], $this->backend->getMultipleCalendarObjects($calendarId, $uris)));
    }

    /**
     * Assumes `public function getCalendarObject($calendarId, $objectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR)`
     * (line 1398), which also returns trashed objects.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null
     */
    public function object(int $calendarId, string $uri): ?array {
        $row = $this->backend->getCalendarObject($calendarId, $uri);
        return $row === null ? null : $this->objectRow($row);
    }

    /**
     * Assumes `public function findCalendarObjectByUid(int $calendarId, string $uid, int $calendarType = self::CALENDAR_TYPE_CALENDAR, ?bool $deleted = false): ?array`
     * (line 1499), which by default skips trashed objects.
     *
     * @param int $calendarId backend calendar id
     * @param string $uid iCalendar UID
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null
     */
    public function objectByUid(int $calendarId, string $uid): ?array {
        $row = $this->backend->findCalendarObjectByUid($calendarId, $uid);
        return $row === null ? null : $this->objectRow($row);
    }

    /**
     * Assumes `public function createCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR)`
     * (line 1538), which returns the quoted ETag and throws UidConflict on a duplicate UID.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri new object URI
     * @param string $data serialized iCalendar
     * @return string new ETag
     */
    public function create(int $calendarId, string $uri, string $data): string {
        return (string)$this->backend->createCalendarObject($calendarId, $uri, $data);
    }

    /**
     * Assumes `public function updateCalendarObject($calendarId, $objectUri, $calendarData, $calendarType = self::CALENDAR_TYPE_CALENDAR)`
     * (line 1615), which returns the quoted ETag.
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     * @param string $data serialized iCalendar
     * @return string new ETag
     */
    public function update(int $calendarId, string $uri, string $data): string {
        return (string)$this->backend->updateCalendarObject($calendarId, $uri, $data);
    }

    /**
     * Assumes `public function moveCalendarObject(string $sourcePrincipalUri, int $sourceObjectId, string $targetPrincipalUri, int $targetCalendarId, string $tragetObjectUri, int $calendarType = self::CALENDAR_TYPE_CALENDAR): bool`
     * (line 1671). The principals must be the calendar owners: the object is looked up with
     * getCalendarObjectById($principalUri, $id) (line 2661), which filters on calendars.principaluri.
     *
     * @param string $sourceOwnerPrincipal owner principal of the source calendar
     * @param int $objectId backend object id
     * @param string $targetOwnerPrincipal owner principal of the target calendar
     * @param int $targetCalendarId backend calendar id of the target
     * @param string $uri object URI in the target
     * @return bool false when the backend could not complete the move
     */
    public function move(string $sourceOwnerPrincipal, int $objectId, string $targetOwnerPrincipal, int $targetCalendarId, string $uri): bool {
        return $this->backend->moveCalendarObject($sourceOwnerPrincipal, $objectId, $targetOwnerPrincipal, $targetCalendarId, $uri);
    }

    /**
     * Assumes `public function deleteCalendarObject($calendarId, $objectUri, $calendarType = self::CALENDAR_TYPE_CALENDAR, bool $forceDeletePermanently = false)`
     * (line 1735). It deletes permanently only when dav/calendarRetentionObligation is '0' (line 1745).
     *
     * @param int $calendarId backend calendar id
     * @param string $uri object URI
     */
    public function delete(int $calendarId, string $uri): void {
        $this->backend->deleteCalendarObject($calendarId, $uri);
    }

    /**
     * @param array<string, mixed> $row backend calendar object row
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}
     */
    private function objectRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'uri' => (string)$row['uri'],
            'etag' => (string)$row['etag'],
            'data' => (string)$row['calendardata'],
            'deleted' => ($row[self::DELETED_AT] ?? null) !== null,
        ];
    }
}
