<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar\References;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceNotFound;
use OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader;
use PHPUnit\Framework\TestCase;
final class CalendarEventReferenceReaderTest extends TestCase
{
    public function testInaccessibleCalendarRetainsOriginalCauseWithoutReadingObjects(): void
    {
        self::assertTrue(
            interface_exists(EventReferenceReader::class),
            'Calendar must expose its reference capability',
        );
        $access = $this->createMock(\OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess::class);
        $cause = \OCA\Mcp\Modules\Calendar\Execution\CalendarException::notFound();
        $access->method('resolve')->willThrowException($cause);
        $store = $this->createMock(\OCA\Mcp\Platform\Dav\CalDav\ObjectStore::class);
        $store->expects($this->never())->method('objectByUid');
        $classification = new \OCA\Mcp\Modules\Calendar\Events\Classification();
        $reader = new CalendarEventReferenceReader(
            $access,
            $store,
            new \OCA\Mcp\Modules\Calendar\Events\EventRepository($store, $classification),
            $classification,
            $this->createMock(\OCP\IURLGenerator::class),
        );
        try {
            $reader->read('alice', '/missing/', 'uid');
            self::fail('Access must be refused');
        } catch (EventReferenceNotFound $e) {
            self::assertSame($cause, $e->getPrevious());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('referenceCases')]
    public function testReferenceVisibilityAndCanonicalLinks(
        string $classification,
        string $owner,
        bool $deleted,
        string $summary,
        bool $refused,
        string $title,
    ): void {
        $access = $this->createMock(\OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess::class);
        $path = '/remote.php/dav/calendars/alice/shared/';
        $access
            ->method('resolve')
            ->with('alice', $path)
            ->willReturn(
                new \OCA\Mcp\Platform\Dav\Collections\Collection(
                    3,
                    'shared',
                    'Shared',
                    $owner,
                    'principals/users/' . $owner,
                    true,
                    $path,
                ),
            );
        $store = $this->createMock(\OCA\Mcp\Platform\Dav\CalDav\ObjectStore::class);
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:uid\r\nDTSTART:20261001T130000Z\r\nSUMMARY:$summary\r\nCLASS:$classification\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:uid\r\nRECURRENCE-ID:20261002T130000Z\r\nSUMMARY:Override\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $store
            ->method('objectByUid')
            ->with(3, 'uid')
            ->willReturn([
                'id' => 9,
                'uri' => 'meeting #é.ics',
                'etag' => 'etag',
                'deleted' => $deleted,
                'data' => $data,
            ]);
        $visibility = new \OCA\Mcp\Modules\Calendar\Events\Classification();
        $urls = $this->createMock(\OCP\IURLGenerator::class);
        $urls->method('getWebroot')->willReturn('/cloud');
        $urls->method('linkToRoute')->with('calendar.view.index')->willReturn('/cloud/apps/calendar/');
        $urls
            ->method('getAbsoluteURL')
            ->willReturnCallback(static fn(string $path): string => 'https://example.test' . $path);
        $reader = new CalendarEventReferenceReader(
            $access,
            $store,
            new \OCA\Mcp\Modules\Calendar\Events\EventRepository($store, $visibility),
            $visibility,
            $urls,
        );
        if ($refused) {
            $this->expectException(EventReferenceNotFound::class);
        }
        $result = $reader->read('alice', $path, 'uid');
        self::assertSame($title, $result['title']);
        self::assertSame(
            'https://example.test/cloud/apps/calendar/edit/' .
                base64_encode('/cloud' . $path . rawurlencode('meeting #é.ics')),
            $result['url'],
        );
    }

    public static function referenceCases(): array
    {
        return [
            'shared public master' => ['PUBLIC', 'bob', false, 'Master', false, 'Master'],
            'private shared' => ['PRIVATE', 'bob', false, 'Hidden', true, ''],
            'private own' => ['PRIVATE', 'alice', false, 'Visible', false, 'Visible'],
            'confidential shared' => ['CONFIDENTIAL', 'bob', false, 'Secret', false, 'Ocupado'],
            'deleted' => ['PUBLIC', 'alice', true, 'Removed', true, ''],
            'untitled' => ['PUBLIC', 'alice', false, '', false, ''],
        ];
    }
}
