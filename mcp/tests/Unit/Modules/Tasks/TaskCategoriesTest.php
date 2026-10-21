<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Verifies reading, writing and filtering task categories (VTODO CATEGORIES) without widening visibility. */
final class TaskCategoriesTest extends TestCase {
    private const PATH = '/remote.php/dav/calendars/alice/tasks/';

    /** @var array<string, string> serialized task by UID, in calendar order */
    private array $tasks = [];
    private string $owner = 'principals/users/alice';
    private CalendarDav $dav;
    private TasksModule $module;

    protected function setUp(): void {
        $this->tasks = ['t1' => $this->task('t1', 'Work')];
        $store = $this->createMockForIntersectionOfInterfaces([CalendarStore::class, \OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class]);
        $store->method('calendarsForPrincipal')->willReturnCallback(
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
            ]
        );
        $row = fn (string $uid) => isset($this->tasks[$uid])
            ? ['id' => 1, 'uri' => $uid . '.ics', 'etag' => '"v1"', 'data' => $this->tasks[$uid], 'deleted' => false]
            : null;
        $store->method('objectByUid')->willReturnCallback(fn (int $calendar, string $uid) => $row($uid));
        $store->method('object')->willReturnCallback(fn (int $calendar, string $uri) => $row(basename($uri, '.ics')));
        $store->method('objects')->willReturnCallback(
            fn (int $calendar, array $uris) => array_values(array_filter(array_map(fn ($uri) => $row(basename($uri, '.ics')), $uris)))
        );
        $uris = $this->createMock(TaskStore::class);
        $uris->method('uris')->willReturnCallback(fn () => array_map(fn ($uid) => $uid . '.ics', array_keys($this->tasks)));
        $this->dav = $this->createMock(CalendarDav::class);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        $this->module = \OCA\Mcp\Tests\Support\Tasks\TasksModuleFactory::create(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($store), $store, $uris, $this->dav, new SharedGuard($this->createMock(IUserManager::class)), new TrashPolicy($config), $time, \OCA\Mcp\Tests\Support\Platform\FileAccess\References\OutputReferenceFilters::inactive($this));
    }

    public function testATaskWithoutCategoriesReadsAsAnEmptyList(): void {
        self::assertSame([], $this->read('t1')['categories']);
        self::assertStringContainsString('BEGIN:VTODO', $this->read('t1')['icalendar'], 'the raw object is still returned');
    }

    public function testASingleLineReadsAsItsValues(): void {
        $this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:Home,Errands\r\n");
        self::assertSame(['Home', 'Errands'], $this->read('t1')['categories']);
    }

    public function testEveryLineIsMergedHonouringEscapedCommasAndIgnoringCaseDuplicates(): void {
        $this->tasks['t1'] = $this->task(
            't1',
            'Work',
            "CATEGORIES:Home, Errands\r\n"
                . "CATEGORIES:errands,Health\\, Fitness\r\n"
                . "CATEGORIES: ,HOME,Garden \r\n"
        );
        $expected = ['Home', 'Errands', 'Health, Fitness', 'Garden'];
        self::assertSame($expected, $this->read('t1')['categories']);
        $listed = $this->json($this->module->call('tasks_list_tasks', ['calendar' => self::PATH], 'alice'))['tasks'];
        self::assertSame($expected, $listed[0]['categories']);
    }

    public function testNamesThatDifferOnlyByUnicodeCaseAreOneCategory(): void {
        $this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:ΟΣ,οσ,Σ,ς\r\n");
        self::assertSame(['ΟΣ', 'Σ'], $this->read('t1')['categories']);
        $plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'New', 'categories' => ['Σ', 'ς', 'σ']], 'alice');
        self::assertSame(['Σ'], $plan['after']['categories']);
    }

    public function testTheFilterFoldsUnicodeCase(): void {
        $this->tasks = [
            't1' => $this->task('t1', 'Sigma', "CATEGORIES:Σ\r\n"),
            't2' => $this->task('t2', 'Word', "CATEGORIES:ΟΣ\r\n"),
            't3' => $this->task('t3', 'Other', "CATEGORIES:Ο\r\n"),
        ];
        self::assertSame(['t1'], array_column($this->list(['category' => 'ς'])['tasks'], 'uid'));
        self::assertSame(['t2'], array_column($this->list(['category' => 'οσ'])['tasks'], 'uid'));
        self::assertSame(['t2'], array_column($this->list(['category' => 'ος'])['tasks'], 'uid'));
    }

    public function testCreateWritesOneMultiValueLineThatReadsBackIntact(): void {
        $args = ['calendar' => self::PATH, 'summary' => 'Shop', 'categories' => [' Errands ', 'Health, Fitness', 'errands']];
        $plan = $this->module->preview('tasks_create_task', $args, 'alice');
        self::assertSame(['Errands', 'Health, Fitness'], $plan['after']['categories']);
        $this->dav->expects(self::once())->method('put')->willReturnCallback(
            function ($user, $calendar, $uri, $data) {
                self::assertSame(1, substr_count($data, 'CATEGORIES'));
                self::assertStringContainsString('CATEGORIES:Errands,Health\\, Fitness', $data);
                $this->tasks[basename($uri, '.ics')] = $data;
                return new DavResult(201);
            }
        );
        $written = $this->json($this->module->call('tasks_create_task', $args + ['confirm' => true], 'alice'));
        self::assertSame(['Errands', 'Health, Fitness'], $written['categories']);
    }

    public function testEditWithoutCategoriesLeavesEveryLineUntouched(): void {
        $lines = "CATEGORIES:Home\r\nCATEGORIES:Errands,home\r\n";
        $this->tasks['t1'] = $this->task('t1', 'Work', $lines);
        $this->expectUpdate(fn (string $data) => self::assertStringContainsString($lines, $data));
        $this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Rest', 'confirm' => true], 'alice');
    }

    public function testEditWithAnEmptyListRemovesEveryLine(): void {
        $this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:Home\r\nCATEGORIES:Errands\r\n");
        $args = ['calendar' => self::PATH, 'uid' => 't1', 'categories' => []];
        self::assertSame([], $this->module->preview('tasks_edit_task', $args, 'alice')['after']['categories']);
        $this->expectUpdate(fn (string $data) => self::assertStringNotContainsString('CATEGORIES', $data));
        self::assertSame([], $this->json($this->module->call('tasks_edit_task', $args + ['confirm' => true], 'alice'))['categories']);
    }

    public function testEditWithAListReplacesEveryLineByOne(): void {
        $this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:Home\r\nCATEGORIES:Errands\r\n");
        $args = ['calendar' => self::PATH, 'uid' => 't1', 'categories' => ['Office', 'A, B']];
        $this->expectUpdate(
            function (string $data) {
                self::assertSame(1, substr_count($data, 'CATEGORIES'));
                self::assertStringContainsString('CATEGORIES:Office,A\\, B', $data);
                self::assertStringContainsString('X-CUSTOM:keep', $data);
            }
        );
        self::assertSame(['Office', 'A, B'], $this->json($this->module->call('tasks_edit_task', $args + ['confirm' => true], 'alice'))['categories']);
    }

    public function testCategoriesDoNotUnlockARecurringTask(): void {
        $this->tasks['t1'] = $this->task('t1', 'Work', "RRULE:FREQ=DAILY\r\n");
        $this->dav->expects(self::never())->method('update');
        $this->expectException(ToolFailure::class);
        $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'categories' => ['Home']], 'alice');
    }

    /** @return array<string, array{mixed}> invalid categories arguments */
    public static function invalidCategoriesProvider(): array {
        return [
            '51 items' => [array_map(fn ($i) => 'c' . $i, range(1, 51))],
            'empty item' => [['Home', '']],
            'blank item' => [['   ']],
            '256 characters' => [[str_repeat('a', 256)]],
            'control character' => [["Home\tWork"]],
            'string instead of list' => ['Home'],
            'number item' => [[1]],
            'map instead of list' => [['a' => 'Home']],
        ];
    }

    #[DataProvider('invalidCategoriesProvider')]
    public function testInvalidCategoriesAreRefusedBeforeAnyWrite(mixed $categories): void {
        $this->dav->expects(self::never())->method('put');
        $this->dav->expects(self::never())->method('update');
        foreach ([
            'tasks_create_task' => ['calendar' => self::PATH, 'summary' => 'New'],
            'tasks_edit_task' => ['calendar' => self::PATH, 'uid' => 't1'],
        ] as $tool => $args) {
            try {
                $this->module->call($tool, $args + ['categories' => $categories, 'confirm' => true], 'alice');
                self::fail($tool . ' accepted invalid categories');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('categor', $e->getMessage());
            }
        }
    }

    public function testTheLimitsThemselvesAreAccepted(): void {
        $categories = array_map(fn ($i) => str_pad((string) $i, 255, 'x'), range(1, 50));
        $plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'New', 'categories' => $categories], 'alice');
        self::assertCount(50, $plan['after']['categories']);
    }

    public function testTheCategoryFilterPagesOverMatchingTasksOnly(): void {
        $this->tasks = [];
        foreach (range(1, 8) as $i) {
            $uid = 't' . $i;
            $extra = ($i % 2 === 0 ? "CATEGORIES:Home\r\n" : "CATEGORIES:Office\r\n")
                . ($i === 4 ? "STATUS:COMPLETED\r\n" : '');
            $this->tasks[$uid] = str_replace("STATUS:NEEDS-ACTION\r\n", $i === 4 ? '' : "STATUS:NEEDS-ACTION\r\n", $this->task($uid, 'Task ' . $i, $extra));
        }
        // Open tasks with Home: t2, t6, t8; t4 is completed.
        $first = $this->list(['category' => 'HOME', 'limit' => 2]);
        self::assertSame(['t2', 't6'], array_column($first['tasks'], 'uid'));
        self::assertTrue($first['hasMore']);
        self::assertSame(2, $first['nextOffset']);
        $second = $this->list(['category' => 'home', 'limit' => 2, 'offset' => 2]);
        self::assertSame(['t8'], array_column($second['tasks'], 'uid'));
        self::assertFalse($second['hasMore']);

        $all = $this->list(['category' => ' home ', 'include_completed' => true, 'limit' => 2, 'offset' => 2]);
        self::assertSame(['t6', 't8'], array_column($all['tasks'], 'uid'));
        self::assertFalse($all['hasMore']);
        self::assertSame([], $this->list(['category' => 'Hom'])['tasks'], 'the comparison is exact, not a prefix');
    }

    public function testProtectedTasksOfASharedCalendarNeverMatchTheFilter(): void {
        $this->owner = 'principals/users/bob';
        $this->tasks = [
            't1' => $this->task('t1', 'Private plan', "CLASS:PRIVATE\r\nCATEGORIES:Secret,Shared\r\n"),
            't2' => $this->task('t2', 'Confidential plan', "CLASS:CONFIDENTIAL\r\nCATEGORIES:Secret,Shared\r\n"),
            't3' => $this->task('t3', 'Public plan', "CATEGORIES:Shared\r\n"),
        ];
        $shared = $this->module->call('tasks_list_tasks', ['calendar' => self::PATH, 'category' => 'Shared'], 'alice');
        self::assertSame(['t3'], array_column($this->json($shared)['tasks'], 'uid'));
        $secret = $this->module->call('tasks_list_tasks', ['calendar' => self::PATH, 'category' => 'Secret'], 'alice');
        self::assertSame([], $this->json($secret)['tasks']);
        foreach ([$shared, $secret] as $result) {
            self::assertStringNotContainsString('Secret', $result['content'][0]['text']);
            self::assertStringNotContainsString('plan', str_replace('Public plan', '', $result['content'][0]['text']));
        }
    }

    /** Serializes a simple task, with extra property lines placed before its summary. */
    private function task(string $uid, string $summary, string $extra = ''): string {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\n"
            . "UID:$uid\r\nDTSTAMP:20261001T100000Z\r\n" . $extra
            . "SUMMARY:$summary\r\nSTATUS:NEEDS-ACTION\r\nX-CUSTOM:keep\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
    }

    /** @return array<string, mixed> the task read through the tool */
    private function read(string $uid): array {
        return $this->json($this->module->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => $uid], 'alice'));
    }

    /** @param array<string, mixed> $arguments listing arguments besides the calendar */
    private function list(array $arguments): array {
        return $this->json($this->module->call('tasks_list_tasks', ['calendar' => self::PATH] + $arguments, 'alice'));
    }

    /** Expects one update of t1, checks its body and stores it so the read-back sees it. */
    private function expectUpdate(callable $check): void {
        $this->dav->expects(self::once())->method('update')->willReturnCallback(
            function ($user, $calendar, $uri, $etag, $data) use ($check) {
                $check($data);
                $this->tasks['t1'] = $data;
                return new DavResult(204);
            }
        );
    }

    private function json(array $result): array {
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
