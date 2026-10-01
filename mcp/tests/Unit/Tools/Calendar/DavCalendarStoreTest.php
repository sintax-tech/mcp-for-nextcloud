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
}
