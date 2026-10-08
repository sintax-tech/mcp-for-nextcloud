<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Calendar;

use OCA\Mcp\Service\Calendar\DavCalendarSelftestReader;
use OCA\Mcp\Tools\Calendar\Calendar;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class DavCalendarSelftestReaderTest extends TestCase {
    public function testConstructingTheReaderDoesNotLoadDav(): void {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');
        new DavCalendarSelftestReader($container);
    }

    public function testOnlyReadMethodsAreCalledAndTrashAndInboxEvidenceAreMapped(): void {
        // Structural fixture: no Nextcloud implementation is loaded or run by this test.
        $backend = new class {
            public array $calls = [];
            public function getCalendarById(int $id): array {
                $this->calls[] = ['calendar', $id];
                return ['{http://sabredav.org/ns}sync-token' => 42, '{http://nextcloud.com/ns}deleted-at' => 123];
            }
            public function getSchedulingObjects(string $principal): array {
                $this->calls[] = ['inbox', $principal];
                return [['uri' => 'i.ics', 'calendardata' => 'ICS', 'etag' => '"e"', 'size' => 3]];
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCA\DAV\CalDAV\CalDavBackend')->willReturn($backend);
        $reader = new DavCalendarSelftestReader($container);
        $calendar = new Calendar(9, 'test', 'test', 'alice', 'principals/users/alice', true, '/test/');
        self::assertSame(['syncToken' => '42', 'deleted' => true], $reader->calendarState($calendar));
        self::assertSame([['uri' => 'i.ics', 'data' => 'ICS', 'etag' => '"e"']], $reader->schedulingObjects('alice'));
        self::assertSame([['calendar', 9], ['inbox', 'principals/users/alice']], $backend->calls);
    }
}
