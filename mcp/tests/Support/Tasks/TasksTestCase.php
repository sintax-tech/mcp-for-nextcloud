<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Tasks;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
use OCA\Mcp\Modules\Tasks\Reading\TaskQuery as TaskStore;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

abstract class TasksTestCase extends TestCase {
    protected const PATH = '/remote.php/dav/calendars/alice/tasks/';
    protected string $data = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//test//EN\r\n"
        . "BEGIN:VTODO\r\n"
        . "UID:t1\r\n"
        . "DTSTAMP:20261001T100000Z\r\n"
        . "SUMMARY:Work\r\n"
        . "STATUS:NEEDS-ACTION\r\n"
        . "X-CUSTOM:keep\r\n"
        . "END:VTODO\r\n"
        . "END:VCALENDAR\r\n";
    protected CalendarDav $dav;
    protected CalendarStore $store;
    protected TasksModule $module;
    protected string $retention = '';
    protected bool $deleted = false;
    protected string $owner = 'principals/users/alice';

    protected function setUp(): void {
        $this->store = $this->createMockForIntersectionOfInterfaces([CalendarStore::class, \OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class]);
        $this->store->method('calendarsForPrincipal')->willReturnCallback(
            fn () => [
                [
                    'id' => 1,
                    'uri' => 'tasks',
                    'displayName' => 'Tasks',
                    'ownerPrincipal' => $this->owner,
                    'readOnly' => false,
                    'components' => ['VTODO'],
                    'deleted' => false,
                ],
                [
                    'id' => 2,
                    'uri' => 'events',
                    'displayName' => 'Events',
                    'ownerPrincipal' => 'principals/users/alice',
                    'readOnly' => false,
                    'components' => ['VEVENT'],
                    'deleted' => false,
                ],
            ]
        );
        $row = fn () => [
            'id' => 1,
            'uri' => 't1.ics',
            'etag' => '"v1"',
            'data' => $this->data,
            'deleted' => $this->deleted,
        ];
        $this->store->method('objectByUid')->willReturnCallback($row);
        // Like the core: once trashed, the row lives under "<name>-deleted.ics" and the original URI is free.
        $this->store->method('object')->willReturnCallback(
            fn (int $calendar, string $uri) => $this->deleted
                ? ($uri === 't1-deleted.ics' ? ['uri' => $uri] + $row() : null)
                : $row()
        );
        $this->store->method('objects')->willReturnCallback(fn () => [$row()]);
        $tasks = $this->createMock(TaskStore::class);
        $tasks->method('uris')->willReturn(['t1.ics']);
        $this->dav = $this->createMock(CalendarDav::class);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn () => $this->retention);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $this->module = \OCA\Mcp\Tests\Support\Tasks\TasksModuleFactory::create(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store), $this->store, $tasks, $this->dav, new SharedGuard($this->createMock(IUserManager::class)), new TrashPolicy($config), $time, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }
}
