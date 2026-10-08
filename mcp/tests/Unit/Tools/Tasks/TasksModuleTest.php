<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCA\Mcp\Tools\ToolFailure;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Verifies task plans, temporal dependencies, shared visibility and calendar trash. */
final class TasksModuleTest extends TestCase {
    private const PATH = '/remote.php/dav/calendars/alice/tasks/';
    private string $data = "BEGIN:VCALENDAR\r\n"
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
    private CalendarDav $dav;
    private CalendarStore $store;
    private TasksModule $module;
    private string $retention = '';
    private bool $deleted = false;
    private string $owner = 'principals/users/alice';

    protected function setUp(): void {
        $this->store = $this->createMock(CalendarStore::class);
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
        $this->module = new TasksModule(
            new CalendarAccess($this->store),
            $this->store,
            $tasks,
            $this->dav,
            new TaskData(),
            new SharedGuard($this->createMock(IUserManager::class)),
            new TrashPolicy($config),
            $time
        );
    }

    public function testOnlyVtodoCalendarsAreListedAndTasksAppIsNotRequired(): void {
        $items = $this->json($this->module->call('tasks_list_calendars', [], 'alice'));
        self::assertCount(1, $items);
        self::assertSame(self::PATH, $items[0]['path']);
        foreach ($this->module->definitions() as $definition) {
            self::assertSame('dav', $definition['app']);
        }
    }

    public function testCompletionPlanAndWritePreserveOtherFields(): void {
        $args = ['calendar' => self::PATH, 'uid' => 't1'];
        $plan = $this->module->preview('tasks_complete_task', $args, 'alice');
        self::assertSame('NEEDS-ACTION', $plan['before']['status']);
        self::assertSame('COMPLETED', $plan['after']['status']);
        self::assertSame(100, $plan['after']['percentComplete']);
        $this->dav->expects(self::once())->method('update')->with(
            'alice',
            'tasks',
            't1.ics',
            '"v1"',
            self::callback(
                function ($data) {
                    self::assertStringContainsString('X-CUSTOM:keep', $data);
                    self::assertStringContainsString('COMPLETED:20261001T120000Z', $data);
                    return true;
                }
            ),
            false
        )->willReturnCallback(
            function ($user, $calendar, $uri, $etag, $data) {
                $this->data = $data;
                return new DavResult(204);
            }
        );
        $this->module->call('tasks_complete_task', $args + ['confirm' => true], 'alice');
    }

    public function testDisabledTrashRefusesDeleteBeforeAnyDispatch(): void {
        $this->retention = '0';
        $this->dav->expects(self::never())->method('delete');
        $this->expectException(ToolFailure::class);
        $this->module->preview('tasks_delete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
    }

    public function testDeleteProvesRecoveryAndUsesCurrentEtag(): void {
        $args = ['calendar' => self::PATH, 'uid' => 't1'];
        self::assertTrue($this->module->preview('tasks_delete_task', $args, 'alice')['recoverable']);
        $this->dav->expects(self::once())->method('delete')->with('alice', 'tasks', 't1.ics', '"v1"', false)->willReturnCallback(
            function () {
                $this->deleted = true;
                return new DavResult(204);
            }
        );
        self::assertTrue(
            $this->json(
                $this->module->call('tasks_delete_task', $args + ['confirm' => true], 'alice')
            )['recoverable']
        );
    }

    public function testDeleteFailsWhenTheTaskIsNotInTheTrashAfterwards(): void {
        $args = ['calendar' => self::PATH, 'uid' => 't1'];
        $this->dav->method('delete')->willReturn(new DavResult(204));
        $this->expectException(\RuntimeException::class);
        $this->module->call('tasks_delete_task', $args + ['confirm' => true], 'alice');
    }

    public function testPrivateSharedTaskIsHidden(): void {
        $this->owner = 'principals/users/bob';
        $this->data = str_replace('SUMMARY:Work', 'CLASS:PRIVATE' . "\r\n" . 'SUMMARY:Work', $this->data);
        self::assertSame(
            [],
            $this->json(
                $this->module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice')
            )['tasks']
        );
        $this->expectException(ToolFailure::class);
        $this->module->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
    }

    /** @return array<string, array{string, string}> the write tool and the class that protects the task */
    public static function protectedWritesProvider(): array {
        $cases = [];
        foreach (['tasks_edit_task', 'tasks_complete_task', 'tasks_delete_task'] as $tool) {
            foreach (['PRIVATE', 'CONFIDENTIAL'] as $class) {
                $cases[$tool . ' on a ' . $class . ' task'] = [$tool, $class];
            }
        }
        return $cases;
    }

    /**
     * P17: the plan of a write shows the task as it is now, so a task that list and read hide must not be
     * shown by the plan of an edit, a completion or a delete either, nor be changed by the confirmed call.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('protectedWritesProvider')]
    public function testAProtectedTaskOfSomebodyElseIsNeitherPlannedNorChanged(string $tool, string $class): void {
        $this->owner = 'principals/users/bob';
        $this->data = str_replace('SUMMARY:Work', 'CLASS:' . $class . "\r\n" . 'SUMMARY:Work', $this->data);
        $this->dav->expects(self::never())->method('update');
        $this->dav->expects(self::never())->method('delete');
        $args = ['calendar' => self::PATH, 'uid' => 't1'] + ($tool === 'tasks_edit_task' ? ['summary' => 'Novo'] : []);

        foreach (['preview', 'call'] as $how) {
            try {
                $how === 'preview'
                    ? $this->module->preview($tool, $args, 'alice')
                    : $this->module->call($tool, $args + ['confirm' => true, 'confirm_shared' => true], 'alice');
                self::fail($how . ' of ' . $tool . ' served a ' . $class . ' task of somebody else');
            } catch (ToolFailure $e) {
                self::assertSame(ToolFailure::NOT_FOUND, $e->getMessage(), $how);
            }
        }
    }

    public function testTheOwnProtectedTaskIsStillPlannedAndRead(): void {
        $this->data = str_replace('SUMMARY:Work', 'CLASS:PRIVATE' . "\r\n" . 'SUMMARY:Work', $this->data);
        $args = ['calendar' => self::PATH, 'uid' => 't1'];

        self::assertSame('Work', $this->module->preview('tasks_complete_task', $args, 'alice')['before']['summary']);
        self::assertSame('Work', $this->json($this->module->call('tasks_read_task', $args, 'alice'))['summary']);
        self::assertCount(1, $this->json($this->module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice'))['tasks']);
    }

    public function testASharedTaskWithoutClassificationIsPlannedForAWriter(): void {
        $this->owner = 'principals/users/bob';

        $plan = $this->module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');

        self::assertSame('Work', $plan['before']['summary']);
        self::assertNotSame([], $plan['shared'], 'the plan says it reaches other people');
    }

    public function testRecurringTaskWriteIsRefusedWithoutDamagingSeries(): void {
        $this->data = str_replace('SUMMARY:Work', 'RRULE:FREQ=DAILY' . "\r\n" . 'SUMMARY:Work', $this->data);
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
    }

    public function testStaleEtagAndForeignCalendarCannotBeChanged(): void {
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->preview(
            'tasks_edit_task',
            [
                'calendar' => self::PATH,
                'uid' => 't1',
                'summary' => 'New',
                'etag' => 'old',
            ],
            'alice'
        );
    }

    public function testDateAndProgressValidation(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->module->preview(
            'tasks_create_task',
            [
                'calendar' => self::PATH,
                'summary' => 'New',
                'start' => '2026-10-02',
                'due' => '2026-10-01',
            ],
            'alice'
        );
    }

    public function testUndatedTasksAreListedAndCompletionReopensWithProgress(): void {
        self::assertCount(
            1,
            $this->json(
                $this->module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice')
            )['tasks']
        );
        $this->data = str_replace(
            'STATUS:NEEDS-ACTION',
            'STATUS:COMPLETED' . "\r\n" . 'PERCENT-COMPLETE:100' . "\r\n" . 'COMPLETED:20261001T110000Z',
            $this->data
        );
        self::assertCount(
            0,
            $this->json(
                $this->module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice')
            )['tasks']
        );
        $plan = $this->module->preview(
            'tasks_edit_task',
            ['calendar' => self::PATH, 'uid' => 't1', 'percentComplete' => 30],
            'alice'
        );
        self::assertSame('IN-PROCESS', $plan['after']['status']);
        self::assertNull($plan['after']['completed']);
    }

    public function testSharedTaskWriteCannotRunWithoutAcknowledgement(): void {
        $this->owner = 'principals/users/bob';
        $args = ['calendar' => self::PATH, 'uid' => 't1'];
        self::assertNotEmpty($this->module->preview('tasks_complete_task', $args, 'alice')['shared']);
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->call('tasks_complete_task', $args + ['confirm' => true], 'alice');
    }

    public function testForeignCalendarAndDeletedTaskAreNotReadable(): void {
        $this->deleted = true;
        $this->expectException(ToolFailure::class);
        $this->module->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
    }

    public function testForeignCalendarCannotBeUsed(): void {
        $this->expectException(ToolFailure::class);
        $this->module->preview(
            'tasks_create_task',
            ['calendar' => '/remote.php/dav/calendars/bob/tasks/', 'summary' => 'New'],
            'alice'
        );
    }

    public function testCreationDispatchesOnceAndContainsVtodoOnly(): void {
        $this->dav->expects(self::once())->method('put')->with(
            'alice',
            'tasks',
            self::stringEndsWith('.ics'),
            self::callback(
                function ($data) {
                    self::assertStringContainsString('BEGIN:VTODO', $data);
                    self::assertStringNotContainsString('BEGIN:VEVENT', $data);
                    $this->data = $data;
                    return true;
                }
            ),
            false
        )->willReturn(new DavResult(201));
        $result = $this->json(
            $this->module->call(
                'tasks_create_task',
                [
                    'calendar' => self::PATH,
                    'summary' => 'New',
                    'due' => '2026-10-05',
                    'confirm' => true,
                ],
                'alice'
            )
        );
        self::assertSame('2026-10-05', $result['due']);
        self::assertSame('New', $result['summary']);
    }

    public function testCreationPlanHasNoUidAndIdenticalCreationsMakeDistinctTasks(): void {
        $args = ['calendar' => self::PATH, 'summary' => 'Buy milk'];
        self::assertArrayNotHasKey('uid', $this->module->preview('tasks_create_task', $args, 'alice')['after']);
        $uris = [];
        $this->dav->method('put')->willReturnCallback(
            function ($user, $calendar, $uri, $data) use (&$uris) {
                $uris[] = $uri;
                $this->data = $data;
                return new DavResult(201);
            }
        );
        $uids = [];
        for ($i = 0; $i < 2; $i++) {
            $uids[] = $this->json($this->module->call('tasks_create_task', $args + ['confirm' => true], 'alice'))['uid'];
        }
        self::assertNotSame($uids[0], $uids[1]);
        self::assertNotSame($uris[0], $uris[1]);
        self::assertSame($uids[0] . '.ics', $uris[0]);
    }

    /** The uid of a new task exists only after the write, so no field of the plan may carry the provisional one, not even inside a blob. */
    public function testCreationPlanExposesNoUidInAnyField(): void {
        $plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Buy milk'], 'alice');
        self::assertArrayNotHasKey('icalendar', $plan['after']);
        self::assertStringNotContainsStringIgnoringCase('UID', json_encode($plan, JSON_THROW_ON_ERROR));
        self::assertSame('Buy milk', $plan['after']['summary']);
    }

    /** Editing keeps the blob: its uid is the real one of a task that already exists. */
    public function testEditPlanKeepsTheCalendarBlobOfAnExistingTask(): void {
        $plan = $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Novo'], 'alice');
        self::assertStringContainsString('UID:t1', $plan['after']['icalendar']);
    }

    public function testRemovingStartCannotLeaveDurationWithoutStart(): void {
        $this->data = str_replace(
            'SUMMARY:Work',
            'DTSTART:20261001T100000Z' . "\r\n" . 'DURATION:PT1H' . "\r\n" . 'SUMMARY:Work',
            $this->data
        );
        $this->dav->expects(self::never())->method('update');
        $this->expectException(InvalidArgumentException::class);
        $this->module->preview(
            'tasks_edit_task',
            ['calendar' => self::PATH, 'uid' => 't1', 'start' => null],
            'alice'
        );
    }

    public function testSettingDueDateReplacesDurationBeforeClearingStart(): void {
        $this->data = str_replace(
            'SUMMARY:Work',
            'DTSTART:20261001T100000Z' . "\r\n" . 'DURATION:PT1H' . "\r\n" . 'SUMMARY:Work',
            $this->data
        );
        $plan = $this->module->preview(
            'tasks_edit_task',
            [
                'calendar' => self::PATH,
                'uid' => 't1',
                'start' => null,
                'due' => '2026-10-02',
            ],
            'alice'
        );
        self::assertStringNotContainsString('DURATION:', $plan['after']['icalendar']);
        self::assertNull($plan['after']['start']);
        self::assertSame('2026-10-02', $plan['after']['due']);
    }

    public function testClearingDueCannotBreakRelativeReminder(): void {
        $this->data = str_replace(
            'END:VTODO',
            'DUE:20261002T120000Z'
                . "\r\n"
                . 'BEGIN:VALARM'
                . "\r\n"
                . 'ACTION:DISPLAY'
                . "\r\n"
                . 'DESCRIPTION:Reminder'
                . "\r\n"
                . 'TRIGGER;RELATED=END:-PT15M'
                . "\r\n"
                . 'END:VALARM'
                . "\r\n"
                . 'END:VTODO',
            $this->data
        );
        $this->dav->expects(self::never())->method('update');
        $this->expectException(InvalidArgumentException::class);
        $this->module->preview(
            'tasks_edit_task',
            ['calendar' => self::PATH, 'uid' => 't1', 'due' => null],
            'alice'
        );
    }

    public function testAbsoluteReminderSurvivesClearedDueDate(): void {
        $this->data = str_replace(
            'END:VTODO',
            'DUE:20261002T120000Z'
                . "\r\n"
                . 'BEGIN:VALARM'
                . "\r\n"
                . 'ACTION:DISPLAY'
                . "\r\n"
                . 'DESCRIPTION:Reminder'
                . "\r\n"
                . 'TRIGGER;VALUE=DATE-TIME:20261002T110000Z'
                . "\r\n"
                . 'END:VALARM'
                . "\r\n"
                . 'END:VTODO',
            $this->data
        );
        $plan = $this->module->preview(
            'tasks_edit_task',
            ['calendar' => self::PATH, 'uid' => 't1', 'due' => null],
            'alice'
        );
        self::assertNull($plan['after']['due']);
        self::assertStringContainsString('TRIGGER;VALUE=DATE-TIME:20261002T110000Z', $plan['after']['icalendar']);
    }

    private function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
