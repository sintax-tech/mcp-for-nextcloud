<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use DateTimeImmutable;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarDav;
use OCA\Mcp\Tests\Support\Dav\FakeCalendarStore;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
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
 * B6, the tasks scope on every task tool (contract B.5 line tasks, S6, S7): a task list outside the scope is missing
 * from tasks_list_calendars, and every read, plan and confirmed write that names it answers exactly what a list that
 * does not exist answers (D6), without writing. The scope is the tasks one, independent of the calendar scope even
 * for the calendar that holds both VEVENT and VTODO.
 */
final class TasksScopeTest extends TestCase {
    private const TODO = '/remote.php/dav/calendars/alice/todo/';
    private const BOTH = '/remote.php/dav/calendars/alice/both/';
    private const MISSING = '/remote.php/dav/calendars/alice/missing/';

    private FakeCalendarStore $store;
    private FakeCalendarDav $dav;
    private ScopeStub $scope;
    private TasksModule $module;

    protected function setUp(): void {
        $this->store = new FakeCalendarStore();
        $this->store->addCalendar('principals/users/alice', 21, 'todo', 'principals/users/alice', ['components' => ['VTODO']]);
        $this->store->addCalendar('principals/users/alice', 22, 'both', 'principals/users/alice', ['components' => ['VEVENT', 'VTODO']]);
        $this->store->addCalendar('principals/users/alice', 23, 'events', 'principals/users/alice', ['components' => ['VEVENT']]);
        foreach ([21 => 'a', 22 => 'b'] as $id => $uid) {
            $this->store->addObject($id, $uid . '.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:$uid\r\n"
                . "DTSTAMP:20261001T100000Z\r\nSUMMARY:Task $uid\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        }
        $this->dav = new FakeCalendarDav($this->store);
        $this->scope = new ScopeStub();
        $tasks = $this->createMock(TaskStore::class);
        $tasks->method('uris')->willReturnCallback(fn (int $id): array => array_keys($this->store->objects[$id] ?? []));
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $this->module = \OCA\Mcp\Tests\Support\Tasks\TasksModuleFactory::create(ScopeStub::access($this->store, $this->scope), $this->store, $tasks, $this->dav,
            new SharedGuard($this->createMock(IUserManager::class)), new TrashPolicy($config), $time,
            \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }

    /** @return list<string> paths tasks_list_calendars returns */
    private function listed(): array {
        $result = $this->module->call('tasks_list_calendars', [], 'alice');
        return array_column(json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR), 'path');
    }

    /** @return array{0:string, 1:string} what the client receives: the result, or the class and message refused with */
    private function outcome(string $name, array $arguments): array {
        try {
            return ['result', json_encode($this->module->call($name, $arguments, 'alice'), JSON_THROW_ON_ERROR)];
        } catch (\Throwable $e) {
            return [$e::class, $e->getMessage()];
        }
    }

    /** @return array<string, array{string, array<string, mixed>}> each names the list through `{c}` */
    public static function calls(): array {
        return [
            'list tasks' => ['tasks_list_tasks', ['calendar' => '{c}']],
            'read task' => ['tasks_read_task', ['calendar' => '{c}', 'uid' => 'b']],
            'create plan' => ['tasks_create_task', ['calendar' => '{c}', 'summary' => 'New']],
            'create confirmed' => ['tasks_create_task', ['calendar' => '{c}', 'summary' => 'New', 'confirm' => true]],
            'edit confirmed' => ['tasks_edit_task', ['calendar' => '{c}', 'uid' => 'b', 'summary' => 'Changed', 'confirm' => true]],
            'complete confirmed' => ['tasks_complete_task', ['calendar' => '{c}', 'uid' => 'b', 'confirm' => true]],
            'delete confirmed' => ['tasks_delete_task', ['calendar' => '{c}', 'uid' => 'b', 'confirm' => true]],
        ];
    }

    private static function naming(array $arguments, string $path): array {
        return array_map(static fn ($value) => $value === '{c}' ? $path : $value, $arguments);
    }

    public function testS1WithoutAScopeEveryTaskListStaysReachable(): void {
        $this->assertSame([self::TODO, self::BOTH], $this->listed());
    }

    #[DataProvider('calls')]
    public function testS7AListOutsideTheScopeAnswersExactlyLikeAMissingOne(string $name, array $arguments): void {
        $this->scope->restrict('tasks', ['21']);
        $this->assertSame([self::TODO], $this->listed());
        $outside = $this->outcome($name, self::naming($arguments, self::BOTH));

        $this->assertNotSame('result', $outside[0]);
        $this->assertSame($this->outcome($name, self::naming($arguments, self::MISSING)), $outside);
        $this->assertSame([], $this->dav->calls);
    }

    #[DataProvider('calls')]
    public function testS6AnEmptyScopeReachesNoTaskList(string $name, array $arguments): void {
        $this->scope->restrict('tasks', []);
        $this->assertSame([], $this->listed());
        $this->assertSame($this->outcome($name, self::naming($arguments, self::MISSING)), $this->outcome($name, self::naming($arguments, self::TODO)));
        $this->assertSame([], $this->dav->calls);
    }

    /** One calendar with VEVENT and VTODO: the calendar scope excluding it leaves its tasks reachable, and the reverse. */
    public function testS7TheCalendarScopeNeverNarrowsTheTaskTools(): void {
        $this->scope->restrict('calendar', ['23']);
        $this->assertSame([self::TODO, self::BOTH], $this->listed());
        $this->assertSame('result', $this->outcome('tasks_read_task', ['calendar' => self::BOTH, 'uid' => 'b'])[0]);
        $this->assertSame([], array_filter($this->scope->asked, static fn (array $q): bool => $q[1] !== 'tasks'));
        // The ids asked are canonical decimal strings.
        $this->assertContains(['alice', 'tasks', '22'], $this->scope->asked);
    }

    public function testInsideTheScopeAConfirmedWriteStillWorks(): void {
        $this->scope->restrict('tasks', ['22']);
        $this->assertSame('result', $this->outcome('tasks_complete_task', ['calendar' => self::BOTH, 'uid' => 'b', 'confirm' => true])[0]);
        $this->assertSame('update', $this->dav->calls[0][0]);
    }

    /** B6-R1: an existing, an out-of-scope and a missing list answer the same 503 when the scope is unreadable. */
    #[DataProvider('calls')]
    public function testS11AnUnreadableScopeAnswersAlikeForAnExistingAndAMissingList(string $name, array $arguments): void {
        $this->scope->restrict('tasks', ['21']);
        $this->scope->unavailable = true;
        $outcomes = array_map(fn (string $path): array => $this->outcome($name, self::naming($arguments, $path)), [self::TODO, self::BOTH, self::MISSING]);
        $this->assertSame(array_fill(0, 3, [PolicyUnavailable::class, 'Resource scope unavailable']), $outcomes);
        $this->assertSame([], $this->dav->calls);
    }

    public function testS11AnUnreadableScopeFailsAUserWithoutAnyTaskListToo(): void {
        $this->store->calendars = [];
        $this->scope->unavailable = true;
        $this->assertSame(PolicyUnavailable::class, $this->outcome('tasks_list_calendars', [])[0]);
    }

    public function testS11AnUnreadableScopeFailsInsteadOfListingEverything(): void {
        $this->scope->unavailable = true;
        foreach ([['tasks_list_calendars', []], ['tasks_list_tasks', ['calendar' => self::TODO]]] as [$name, $arguments]) {
            $this->assertSame(PolicyUnavailable::class, $this->outcome($name, $arguments)[0], $name);
        }
        $this->assertSame([], $this->dav->calls);
    }
}
