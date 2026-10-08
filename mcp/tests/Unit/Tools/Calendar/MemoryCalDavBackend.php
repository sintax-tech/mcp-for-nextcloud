<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use Sabre\CalDAV\Backend\AbstractBackend;
use Sabre\CalDAV\Backend\SyncSupport;

/**
 * In-memory CalDAV backend for the dispatcher tests: enough of the Sabre contract for a real
 * Sabre\DAV\Server to route PUT, DELETE and MOVE through its own plugins.
 */
final class MemoryCalDavBackend extends AbstractBackend implements SyncSupport {
    /** @var array<int, array{id:int, uri:string, principaluri:string, displayname:string}> calendars by id */
    public array $calendars = [];
    /** @var array<int, array<string, array{id:int, uri:string, calendardata:string, etag:string}>> objects by calendar id and URI */
    public array $objects = [];
    /** @var list<string> backend calls, for assertions about the official pipeline */
    public array $calls = [];
    private int $nextCalendarId = 1;
    private int $nextObjectId = 1;
    private int $revision = 0;

    /**
     * @param string $principalUri owner principal
     * @param string $uri calendar URI
     * @param string $displayName calendar display name
     * @return int backend calendar id
     */
    public function addCalendar(string $principalUri, string $uri, string $displayName = 'Calendar'): int {
        $id = $this->nextCalendarId++;
        $this->calendars[$id] = ['id' => $id, 'uri' => $uri, 'principaluri' => $principalUri, 'displayname' => $displayName, 'shared' => []];
        return $id;
    }

    /**
     * Shows a calendar of one principal in another principal's home, as the Nextcloud backend does.
     *
     * @param int $calendarId calendar to share
     * @param string $viewerPrincipal principal the calendar becomes visible to
     * @param bool $readOnly true to expose the {http://sabredav.org/ns}read-only flag Sabre reads
     * @return void
     */
    public function share(int $calendarId, string $viewerPrincipal, bool $readOnly = true): void {
        $this->calendars[$calendarId]['shared'][] = ['principaluri' => $viewerPrincipal, 'read-only' => $readOnly];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $uri object URI
     * @param string $ics iCalendar payload
     * @return string new ETag
     */
    public function addObject(int $calendarId, string $uri, string $ics): string {
        $etag = '"' . md5($ics) . '"';
        $this->objects[$calendarId][$uri] = ['id' => $this->nextObjectId++, 'uri' => $uri, 'calendardata' => $ics, 'etag' => $etag];
        return $etag;
    }

    /**
     * @param string $principalUri principal URI
     * @return list<array<string, mixed>> calendars owned by the principal plus the ones shared with it
     */
    public function getCalendarsForUser($principalUri): array {
        $out = [];
        foreach ($this->calendars as $calendar) {
            if ($calendar['principaluri'] === $principalUri) {
                $out[] = $calendar;
                continue;
            }
            foreach ($calendar['shared'] as $share) {
                if ($share['principaluri'] !== $principalUri) {
                    continue;
                }
                $row = $calendar;
                unset($row['shared']);
                $row['uri'] = $calendar['uri'] . '_shared_by_' . substr($calendar['principaluri'], strlen('principals/users/'));
                // nextcloud/server stable33 builds a shared row with the requesting principal as
                // `principaluri` and the real owner in `{http://owncloud.org/ns}owner-principal`
                // (apps/dav/lib/CalDAV/CalDavBackend.php:435-444), which is what lets Sabre's
                // Calendar grant the write privilege to the sharee (sabre/dav/lib/CalDAV/Calendar.php:253-286).
                $row['principaluri'] = $principalUri;
                $row['{http://owncloud.org/ns}owner-principal'] = $calendar['principaluri'];
                // Sabre's Calendar reads this key to decide whether {DAV:}write is in the ACL.
                if ($share['read-only']) {
                    $row['{http://sabredav.org/ns}read-only'] = true;
                }
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param mixed $calendarId calendar id
     * @return list<array<string, mixed>>
     */
    public function getCalendarObjects($calendarId): array {
        $this->calls[] = 'getCalendarObjects';
        return array_values($this->objects[$calendarId] ?? []);
    }

    /**
     * @param mixed $calendarId calendar id
     * @param string $objectUri object URI
     * @return array<string, mixed>|null
     */
    public function getCalendarObject($calendarId, $objectUri): ?array {
        return $this->objects[$calendarId][$objectUri] ?? null;
    }

    /**
     * @param mixed $calendarId calendar id
     * @param string $objectUri object URI
     * @param string $calendarData iCalendar payload
     * @return string new ETag
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData): string {
        $this->calls[] = 'createCalendarObject';
        if (isset($this->objects[$calendarId][$objectUri])) {
            throw new \Sabre\DAV\Exception\PreconditionFailed('An If-None-Match header was specified and the resource already exists.');
        }
        return $this->addObject((int)$calendarId, (string)$objectUri, (string)$calendarData);
    }

    /**
     * @param mixed $calendarId calendar id
     * @param string $objectUri object URI
     * @param string $calendarData iCalendar payload
     * @return string new ETag
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData): string {
        $this->calls[] = 'updateCalendarObject';
        if (!isset($this->objects[$calendarId][$objectUri])) {
            throw new \Sabre\DAV\Exception\NotFound('Object not found');
        }
        return $this->addObject((int)$calendarId, (string)$objectUri, (string)$calendarData);
    }

    /**
     * @param mixed $calendarId calendar id
     * @param string $objectUri object URI
     * @return bool
     */
    public function deleteCalendarObject($calendarId, $objectUri): bool {
        $this->calls[] = 'deleteCalendarObject';
        unset($this->objects[$calendarId][$objectUri]);
        return true;
    }

    /**
     * @param mixed $calendarId calendar id
     * @return array<string, mixed>|null
     */
    public function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null): ?array {
        $this->revision++;
        return ['syncToken' => 'http://sabre.io/ns/sync/' . $this->revision, 'added' => [], 'modified' => [], 'deleted' => []];
    }

    /**
     * @param mixed $calendarId calendar id
     * @return void
     */
    public function deleteCalendar($calendarId): void {
        $this->calls[] = 'deleteCalendar';
        unset($this->calendars[(int)$calendarId], $this->objects[(int)$calendarId]);
    }

    /**
     * @param string $principalUri principal URI
     * @param string $uri calendar URI
     * @param array<string, mixed> $properties calendar properties
     * @return bool
     */
    public function createCalendar($principalUri, $uri, array $properties): bool {
        $this->calls[] = 'createCalendar';
        $this->addCalendar($principalUri, $uri, (string)($properties['{DAV:}displayname'] ?? $uri));
        return true;
    }

    /**
     * @param string $principalUri principal URI
     * @param string $uri calendar URI
     * @return array<string, mixed>|null
     */
    public function getCalendarObjectByUID($principalUri, $uid): ?array {
        foreach ($this->getCalendarsForUser($principalUri) as $calendar) {
            foreach ($this->objects[$calendar['id']] ?? [] as $object) {
                if (preg_match('/^UID:' . preg_quote($uid, '/') . '\r?$/m', $object['calendardata']) === 1) {
                    return ['calendarid' => $calendar['id'], 'uri' => $object['uri']];
                }
            }
        }
        return null;
    }
}