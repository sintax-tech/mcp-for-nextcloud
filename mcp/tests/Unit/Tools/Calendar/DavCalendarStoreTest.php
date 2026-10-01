<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\DavCalendarStore;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\Calendar\CalendarException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DavCalendarStoreTest extends TestCase {
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
