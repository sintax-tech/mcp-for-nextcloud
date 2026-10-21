<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use OCA\Mcp\Tests\Support\Tasks\TasksModuleFactory;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess;

use DateTimeImmutable;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarDav;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarStore;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice as SharedGuard;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
use OCA\Mcp\Modules\Tasks\Reading\TaskQuery as TaskStore;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A calendar holding both components. The task tools never reach an event by its UID: reads, plans and confirmed writes answer
 * exactly what an unknown UID answers, and nothing is written.
 */
final class TasksCrossComponentTest extends TestCase {
    private const BOTH = '/remote.php/dav/calendars/alice/both/';

    private FakeCalendarStore $store;
    private FakeCalendarDav $dav;
    private TasksModule $module;

    protected function setUp(): void {
        $this->store = new FakeCalendarStore();
        $this->store->addCalendar('principals/users/alice', 22, 'both', 'principals/users/alice', ['components' => ['VEVENT', 'VTODO']]);
        $this->store->addObject(22, 'ev1.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VEVENT\r\nUID:ev1\r\n"
            . "DTSTAMP:20261001T100000Z\r\nDTSTART:20261002T100000Z\r\nDTEND:20261002T110000Z\r\nSUMMARY:An event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $this->dav = new FakeCalendarDav($this->store);
        $tasks = $this->createMock(TaskStore::class);
        $tasks->method('uris')->willReturn([]);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $this->module = TasksModuleFactory::create(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store), $this->store, $tasks, $this->dav, new SharedGuard($this->createMock(IUserManager::class)), new TrashPolicy($config), $time, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }

    /** @return array<string, array{string, array<string, mixed>, bool}> name, arguments and whether it is a plan */
    public static function calls(): array {
        return [
            'read' => ['tasks_read_task', [], false],
            'edit plan' => ['tasks_edit_task', ['summary' => 'Changed'], true],
            'edit confirmed' => ['tasks_edit_task', ['summary' => 'Changed', 'confirm' => true], false],
            'complete plan' => ['tasks_complete_task', [], true],
            'complete confirmed' => ['tasks_complete_task', ['confirm' => true], false],
            'delete plan' => ['tasks_delete_task', [], true],
            'delete confirmed' => ['tasks_delete_task', ['confirm' => true], false],
        ];
    }

    /** @return array{0:string, 1:string} the result, or the class and message refused with */
    private function outcome(string $name, array $arguments, bool $plan): array {
        try {
            $result = $plan ? $this->module->preview($name, $arguments, 'alice') : $this->module->call($name, $arguments, 'alice');
            return ['result', json_encode($result, JSON_THROW_ON_ERROR)];
        } catch (\Throwable $e) {
            return [$e::class, $e->getMessage()];
        }
    }

    #[DataProvider('calls')]
    public function testATaskToolAnswersAnEventUidExactlyLikeAnUnknownOne(string $name, array $arguments, bool $plan): void {
        $event = $this->outcome($name, ['calendar' => self::BOTH, 'uid' => 'ev1'] + $arguments, $plan);
        $missing = $this->outcome($name, ['calendar' => self::BOTH, 'uid' => 'no-such-uid'] + $arguments, $plan);

        $this->assertNotSame('result', $event[0], $event[1]);
        $this->assertSame($missing, $event);
        $this->assertSame([], $this->dav->calls);
        $this->assertArrayHasKey('ev1.ics', $this->store->objects[22]);
    }
}
