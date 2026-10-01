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
     * @param string|false|null $fetched what the `uri` query returns
     * @param \Closure|null $backendSetup receives the CalDavBackend mock before the call
     * @return array{store: DavCalendarStore, qb: IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function storeWithUriQuery(string|false|null $fetched, ?\Closure $backendSetup = null): array {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn ($a, $b): string => "$a=$b");
        $expr->method('isNull')->willReturnCallback(static fn ($a): string => "$a IS NULL");
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn($fetched);
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
        if ($backendSetup !== null) {
            $backendSetup($backend);
        }
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([[IDBConnection::class, $db], ['OCA\DAV\CalDAV\CalDavBackend', $backend]]);
        return ['store' => new DavCalendarStore($container), 'qb' => $qb];
    }

    public function testObjectByUidQueriesOnlyLiveObjectsOfThatCalendarAndLoadsTheRowByUri(): void {
        $wheres = [];
        $built = $this->storeWithUriQuery('ev.ics', function ($backend): void {
            $backend->expects($this->once())->method('getCalendarObject')->with(7, 'ev.ics')->willReturn([
                'id' => 11, 'uri' => 'ev.ics', 'etag' => '"e"', 'calendardata' => 'ICS',
            ]);
        });
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

    public function testObjectByUidReturnsNullWithoutTouchingTheBackendWhenNoRowMatches(): void {
        foreach ([false, null] as $fetched) {
            $built = $this->storeWithUriQuery($fetched, function ($backend): void {
                $backend->expects($this->never())->method('getCalendarObject');
            });
            self::assertNull($built['store']->objectByUid(7, 'missing'));
        }
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
}
