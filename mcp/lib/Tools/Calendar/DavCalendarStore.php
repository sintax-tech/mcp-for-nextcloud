<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use DateTime;
use DateTimeImmutable;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;

/**
 * Thin adapter from CalendarStore to OCA\DAV\CalDAV\CalDavBackend (nextcloud/server stable33).
 *
 * Only translates arguments and result shapes; it deliberately holds no business rule.
 * Line numbers refer to apps/dav/lib/CalDAV/CalDavBackend.php on branch stable33.
 */
class DavCalendarStore implements CalendarStore {
    /** Value of `calendarobjects.calendartype` for regular calendars (CalDavBackend::CALENDAR_TYPE_CALENDAR). */
    private const CALENDAR_TYPE_CALENDAR = 0;
    /** Row key of the owner principal (OCA\DAV\DAV\Sharing\Plugin::NS_OWNCLOUD). */
    private const OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
    /** Row key set only on calendars shared with the principal. */
    private const READ_ONLY = '{http://owncloud.org/ns}read-only';
    /** Row key of the trash timestamp (OCA\DAV\DAV\Sharing\Plugin::NS_NEXTCLOUD). */
    private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';
    /** Row key of the supported component set. */
    private const COMPONENTS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

    /** Backend resolved on first use, so a DAV app that fails to load only breaks calendar calls. */
    private ?CalDavBackend $backend = null;

    /**
     * @param ContainerInterface $container server container that provides the DAV app's CalDavBackend
     */
    public function __construct(private ContainerInterface $container) {}

    /**
     * @return CalDavBackend the DAV app's CalDAV backend
     * @throws \Psr\Container\ContainerExceptionInterface when the DAV app cannot provide it
     */
    private function backend(): CalDavBackend {
        return $this->backend ??= $this->container->get(CalDavBackend::class);
    }

    /**
     * Assumes `public function getCalendarsForUser($principalUri)` (line 320), which returns owned
     * and shared calendars; shared rows carry the "_shared_by_<owner>" URI and the read-only flag.
     *
     * @param string $principalUri e.g. "principals/users/alice"
     * @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}>
     */
    public function calendarsForPrincipal(string $principalUri): array {
        $out = [];
        foreach ($this->backend()->getCalendarsForUser($principalUri) as $row) {
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
        return array_values(array_map('strval', $this->backend()->calendarQuery($calendarId, $filters)));
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
        return array_values(array_map([$this, 'objectRow'], $this->backend()->getMultipleCalendarObjects($calendarId, $uris)));
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
        $row = $this->backend()->getCalendarObject($calendarId, $uri);
        return $row === null ? null : $this->objectRow($row);
    }

    /**
     * Looks the live object up by its UID inside one calendar. `CalDavBackend::findCalendarObjectByUid()`
     * is not part of every Nextcloud 33 release (it is missing in 33.0.2), so the URI comes from a query on
     * `calendarobjects` and the row from the stable `getCalendarObject()` (line 1398).
     *
     * @param int $calendarId backend calendar id
     * @param string $uid iCalendar UID
     * @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null
     * @throws \Psr\Container\ContainerExceptionInterface when the DAV app or the database cannot be resolved
     */
    public function objectByUid(int $calendarId, string $uid): ?array {
        $qb = $this->container->get(IDBConnection::class)->getQueryBuilder();
        $result = $qb->select('uri')
            ->from('calendarobjects')
            ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('calendartype', $qb->createNamedParameter(self::CALENDAR_TYPE_CALENDAR, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('deleted_at'))
            ->setMaxResults(1)
            ->executeQuery();
        $uri = $result->fetchOne();
        $result->closeCursor();
        if ($uri === false || $uri === null) {
            return null;
        }
        $row = $this->backend()->getCalendarObject($calendarId, (string)$uri);
        return $row === null ? null : $this->objectRow($row);
    }

    /**
     * Assumes `public function getShares(int $resourceId): array`, whose rows carry `href`
     * ("principal:principals/users/<uid>" or ".../groups/<gid>") and `readOnly`.
     *
     * @param int $calendarId backend calendar id
     * @return list<array{principal:string, readOnly:bool}>
     */
    public function sharesOf(int $calendarId): array {
        $out = [];
        foreach ($this->backend()->getShares($calendarId) as $row) {
            $href = (string)($row['href'] ?? '');
            $out[] = [
                'principal' => str_starts_with($href, 'principal:') ? substr($href, strlen('principal:')) : $href,
                'readOnly' => (bool)($row['readOnly'] ?? false),
            ];
        }
        return $out;
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
