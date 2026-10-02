<?php
declare(strict_types=1);

namespace OCA\DAV\CalDAV;

/**
 * Test-only shape of the Nextcloud 33 CalDAV backend: only the methods a unit test needs to mock, with the
 * signatures of v33.0.2. It is loaded explicitly by the tests that need it, never by the autoloader.
 */
class CalDavBackend {
    /**
     * @param int $calendarId backend calendar id
     * @param string $objectUri object URI
     * @param int $calendarType 0 for a regular calendar
     * @return array<string, mixed>|null
     */
    public function getCalendarObject($calendarId, $objectUri, int $calendarType = 0) {
        return null;
    }

    /**
     * @param int $resourceId backend calendar id
     * @return list<array<string, mixed>> share rows ("href" like "principal:principals/users/bob", "readOnly")
     */
    public function getShares($resourceId) {
        return [];
    }

    /**
     * @param string $principalUri principal of the user, e.g. "principals/users/alice"
     * @return list<array<string, mixed>> calendar rows, owned and shared, keyed as the DAV properties
     */
    public function getCalendarsForUser($principalUri) {
        return [];
    }

    /**
     * @param int $calendarId backend calendar id
     * @param array<string, mixed> $filters Sabre CalendarQueryParser filter
     * @param int $calendarType 0 for a regular calendar
     * @return array<int, string> object URIs
     */
    public function calendarQuery($calendarId, array $filters, $calendarType = 0): array {
        return [];
    }

    /**
     * @param int $calendarId backend calendar id
     * @param array<int, string> $uris object URIs
     * @param int $calendarType 0 for a regular calendar
     * @return array<int, array<string, mixed>> object rows
     */
    public function getMultipleCalendarObjects($calendarId, array $uris, $calendarType = 0): array {
        return [];
    }
}
