<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\DavCalendarStore;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DavCalendarStoreTest extends TestCase {
    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../../stubs/OCA/DAV/CalDAV/CalDavBackend.php';
    }

    /**
     * @param array<string, mixed>|false $fetched row the `calendarobjects` query returns
     * @return array{store: DavCalendarStore, qb: IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject, backend: \PHPUnit\Framework\MockObject\MockObject}
     */
    private function storeWithRowQuery(array|false $fetched): array {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn ($a, $b): string => "$a=$b");
        $expr->method('isNull')->willReturnCallback(static fn ($a): string => "$a IS NULL");
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchAssociative')->willReturn($fetched);
        $result->expects($this->once())->method('closeCursor');
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(static fn ($v): string => ':' . $v);
        foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([[IDBConnection::class, $db], ['OCA\DAV\CalDAV\CalDavBackend', $backend]]);
        return ['store' => new DavCalendarStore($container), 'qb' => $qb, 'backend' => $backend];
    }

    public function testObjectByUidQueriesOnlyLiveObjectsOfThatCalendarAndBypassesTheBackendCache(): void {
        $wheres = [];
        $built = $this->storeWithRowQuery(['id' => '11', 'uri' => 'ev.ics', 'etag' => 'e', 'calendardata' => 'ICS', 'deleted_at' => null]);
        $built['backend']->expects($this->never())->method('getCalendarObject');
        $built['qb']->method('where')->willReturnCallback(function ($c) use (&$wheres, $built) {
            $wheres[] = $c;
            return $built['qb'];
        });
        $built['qb']->method('andWhere')->willReturnCallback(function ($c) use (&$wheres, $built) {
            $wheres[] = $c;
            return $built['qb'];
        });
        self::assertSame(
            ['id' => 11, 'uri' => 'ev.ics', 'etag' => '"e"', 'data' => 'ICS', 'deleted' => false],
            $built['store']->objectByUid(7, 'uid-1'),
        );
        self::assertSame(['calendarid=:7', 'uid=:uid-1', 'calendartype=:0', 'deleted_at IS NULL'], $wheres);
    }

    public function testObjectReadsTheCurrentRowFromTheDatabaseNotTheBackendCache(): void {
        // The backend memoizes getCalendarObject(); after a write through the DAV server it still holds the old row.
        $built = $this->storeWithRowQuery(['id' => 11, 'uri' => 'ev.ics', 'etag' => 'new', 'calendardata' => 'NEW', 'deleted_at' => null]);
        $built['backend']->expects($this->never())->method('getCalendarObject');
        self::assertSame(
            ['id' => 11, 'uri' => 'ev.ics', 'etag' => '"new"', 'data' => 'NEW', 'deleted' => false],
            $built['store']->object(7, 'ev.ics'),
        );
    }

    public function testObjectFlagsTrashedRowsAndReadsBlobStreams(): void {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'ICS');
        rewind($stream);
        $built = $this->storeWithRowQuery(['id' => 1, 'uri' => 'ev-deleted.ics', 'etag' => 'e', 'calendardata' => $stream, 'deleted_at' => '1790000000']);
        self::assertSame(
            ['id' => 1, 'uri' => 'ev-deleted.ics', 'etag' => '"e"', 'data' => 'ICS', 'deleted' => true],
            $built['store']->object(7, 'ev-deleted.ics'),
        );
    }

    public function testObjectAndObjectByUidReturnNullWhenNoRowMatches(): void {
        self::assertNull($this->storeWithRowQuery(false)['store']->objectByUid(7, 'missing'));
        self::assertNull($this->storeWithRowQuery(false)['store']->object(7, 'missing.ics'));
    }

    public function testBuildingTheStoreDoesNotResolveTheDavBackend(): void {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');
        new DavCalendarStore($container);
    }

    public function testBackendFailureSurfacesOnlyWhenACalendarCallRuns(): void {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with('OCA\DAV\CalDAV\CalDavBackend')->willThrowException(new \RuntimeException('dav not loaded'));
        $this->expectException(\RuntimeException::class);
        (new DavCalendarStore($container))->calendarsForPrincipal('principals/users/alice');
    }

    public function testCalendarExceptionIsAToolFailureWithTheSameMessage(): void {
        $this->assertInstanceOf(ToolFailure::class, CalendarException::notFound());
        $this->assertSame('Calendar or event not found.', CalendarException::notFound()->getMessage());
    }

    /**
     * @param \Closure $backendSetup receives the CalDavBackend mock before the call
     */
    private function storeWithShares(\Closure $backendSetup): DavCalendarStore {
        $backend = $this->createMock(\OCA\DAV\CalDAV\CalDavBackend::class);
        $backendSetup($backend);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([['OCA\DAV\CalDAV\CalDavBackend', $backend]]);
        return new DavCalendarStore($container);
    }

    public function testSharesOfNormalizesTheCoreHrefAndReadOnlyFlag(): void {
        $store = $this->storeWithShares(function ($backend): void {
            $backend->expects($this->once())->method('getShares')->with(7)->willReturn([
                ['href' => 'principal:principals/users/bob', 'readOnly' => false, 'commonName' => 'Bob'],
                ['href' => 'principal:principals/groups/sales', 'readOnly' => 1],
                ['href' => 'principals/users/carol', 'readOnly' => true],
            ]);
        });
        self::assertSame([
            ['principal' => 'principals/users/bob', 'readOnly' => false],
            ['principal' => 'principals/groups/sales', 'readOnly' => true],
            ['principal' => 'principals/users/carol', 'readOnly' => true],
        ], $store->sharesOf(7));
    }

    public function testSharesOfReturnsEmptyListWhenNothingIsShared(): void {
        $store = $this->storeWithShares(function ($backend): void {
            $backend->method('getShares')->willReturn([]);
        });
        self::assertSame([], $store->sharesOf(7));
    }

    /**
     * The rows the core builds for alice: her own calendar, a read-write share from bob, a read-only share from bob
     * and a calendar in the trash. Keys are the DAV property names CalDavBackend::getCalendarsForUser() writes.
     *
     * @return list<array<string, mixed>> raw backend rows
     */
    private static function coreRows(): array {
        $components = static fn (array $names) => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet($names);
        return [
            ['id' => '1', 'uri' => 'personal', 'principaluri' => 'principals/users/alice', '{DAV:}displayname' => 'Pessoal',
                '{http://owncloud.org/ns}owner-principal' => 'principals/users/alice',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $components(['VEVENT', 'VTODO'])],
            ['id' => '3', 'uri' => 'team_shared_by_bob', 'principaluri' => 'principals/users/alice', '{DAV:}displayname' => 'Equipe',
                '{http://owncloud.org/ns}owner-principal' => 'principals/users/bob',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $components(['VEVENT'])],
            ['id' => '4', 'uri' => 'private_shared_by_bob', 'principaluri' => 'principals/users/alice',
                '{http://owncloud.org/ns}owner-principal' => 'principals/users/bob', '{http://owncloud.org/ns}read-only' => true,
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $components(['VEVENT'])],
            ['id' => '7', 'uri' => 'old', 'principaluri' => 'principals/users/alice', '{http://nextcloud.com/ns}deleted-at' => 1_790_000_000],
        ];
    }

    /** The owner, the read-only flag, the trash flag and the component set come out of the core's DAV property keys. */
    public function testCalendarsForPrincipalMapsOwnerReadOnlyTrashAndComponents(): void {
        $store = $this->storeWithShares(function ($backend): void {
            $backend->expects($this->once())->method('getCalendarsForUser')->with('principals/users/alice')->willReturn(self::coreRows());
        });

        self::assertSame([
            ['id' => 1, 'uri' => 'personal', 'displayName' => 'Pessoal', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false, 'components' => ['VEVENT', 'VTODO'], 'deleted' => false],
            ['id' => 3, 'uri' => 'team_shared_by_bob', 'displayName' => 'Equipe', 'ownerPrincipal' => 'principals/users/bob', 'readOnly' => false, 'components' => ['VEVENT'], 'deleted' => false],
            ['id' => 4, 'uri' => 'private_shared_by_bob', 'displayName' => 'private_shared_by_bob', 'ownerPrincipal' => 'principals/users/bob', 'readOnly' => true, 'components' => ['VEVENT'], 'deleted' => false],
            ['id' => 7, 'uri' => 'old', 'displayName' => 'old', 'ownerPrincipal' => 'principals/users/alice', 'readOnly' => false, 'components' => [], 'deleted' => true],
        ], $store->calendarsForPrincipal('principals/users/alice'));
    }

    /** A row with no owner-principal falls back to the principal that holds it. */
    public function testOwnerFallsBackToThePrincipalOfTheRow(): void {
        $store = $this->storeWithShares(function ($backend): void {
            $backend->method('getCalendarsForUser')->willReturn([['id' => 9, 'uri' => 'x', 'principaluri' => 'principals/users/alice']]);
        });
        self::assertSame('principals/users/alice', $store->calendarsForPrincipal('principals/users/alice')[0]['ownerPrincipal']);
    }

    /** The time window reaches the core as a VEVENT time-range with the same from and to, never swapped. */
    public function testEventUrisInRangeSendsTheTimeRangeAsAVeventFilter(): void {
        $from = new \DateTimeImmutable('2026-03-01T00:00:00Z');
        $to = new \DateTimeImmutable('2026-04-01T00:00:00Z');
        $store = $this->storeWithShares(function ($backend) use ($from, $to): void {
            $backend->expects($this->once())->method('calendarQuery')->with(
                7,
                $this->callback(function (array $filters) use ($from, $to): bool {
                    $range = $filters['comp-filters'][0]['time-range'] ?? null;
                    return $filters['name'] === 'VCALENDAR'
                        && $filters['comp-filters'][0]['name'] === 'VEVENT'
                        && $range !== null
                        && $range['start']->format(DATE_ATOM) === $from->format(DATE_ATOM)
                        && $range['end']->format(DATE_ATOM) === $to->format(DATE_ATOM);
                }),
            )->willReturn(['a.ics', 'b.ics']);
        });
        self::assertSame(['a.ics', 'b.ics'], $store->eventUrisInRange(7, $from, $to));
    }

    /** Rows come back keyed the way the tools read them; an empty list never reaches the core. */
    public function testObjectsMapsTheCoreRowsAndSkipsTheCallForNoUris(): void {
        $store = $this->storeWithShares(function ($backend): void {
            $backend->expects($this->once())->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
                ['id' => '5', 'uri' => 'a.ics', 'etag' => '"abc"', 'calendardata' => 'ICS'],
            ]);
        });
        self::assertSame([], $store->objects(7, []));
        self::assertSame([['id' => 5, 'uri' => 'a.ics', 'etag' => '"abc"', 'data' => 'ICS', 'deleted' => false]], $store->objects(7, ['a.ics']));
    }
}
