<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use DateTimeImmutable;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the text a person reads before a task is created, changed, completed or deleted.
 *
 * The plan is the one the module really returns, built with the same fakes the module test
 * uses, and read through the module: what the renderer shows is what a person is asked to
 * confirm. Dates are read in the timezone of the account, so a due date never moves a day.
 */
final class TasksPlanRendererTest extends TestCase {
    use \OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;

    private const PATH = '/remote.php/dav/calendars/alice/tasks/';
    private string $data = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//test//EN\r\n"
        . "BEGIN:VTODO\r\n"
        . "UID:t1\r\n"
        . "DTSTAMP:20261001T100000Z\r\n"
        . "SUMMARY:Buy milk\r\n"
        . "STATUS:NEEDS-ACTION\r\n"
        . "X-CUSTOM:keep\r\n"
        . "END:VTODO\r\n"
        . "END:VCALENDAR\r\n";

    protected function setUp(): void {
        Translator::reset();
    }

    protected function tearDown(): void {
        Translator::reset();
    }

    public function testCreateNamesTheTaskItsCalendarAndItsDatesInPortuguese(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->render(
            'tasks_create_task',
            [
                'calendar' => self::PATH,
                'summary' => 'Comprar leite',
                'due' => '2026-10-05',
                'start' => '2026-10-02T09:00:00Z',
                'due' => '2026-10-05T18:00:00Z',
                'priority' => 3,
                'description' => str_repeat('texto longo ', 60),
            ]
        );
        self::assertIsString($body);
        self::assertStringContainsString('Comprar leite', $body);
        self::assertStringContainsString('05/10/2026', $body);
        // 09:00 UTC is 06:00 in São Paulo: the person reads the hour they live in.
        self::assertStringContainsString('02/10/2026 06:00', $body);
        // The calendar is named as its owner named it, not as an id or a path.
        self::assertStringContainsString('Tasks', $body);
        self::assertStringNotContainsString(self::PATH, $body);
        self::assertStringNotContainsString('uid', $body);
        self::assertStringNotContainsString('t1', $body);
        self::assertStringNotContainsString('requiresConfirmation', $body);
        // A long description is an excerpt only.
        self::assertStringNotContainsString(str_repeat('texto longo ', 60), $body);
        self::assertLessThanOrEqual(500, mb_strlen($body));
    }

    public function testCreateReadsAsEnglishWhenTheUserReadsEnglish(): void {
        $body = $this->render('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Buy milk']);
        self::assertIsString($body);
        self::assertStringContainsString('Buy milk', $body);
        self::assertStringContainsString('Tasks', $body);
    }

    public function testEditShowsOnlyWhatChangesFromTheOldValueToTheNewOne(): void {
        $body = $this->render(
            'tasks_edit_task',
            ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Buy oat milk', 'percentComplete' => 50]
        );
        self::assertIsString($body);
        self::assertStringContainsString('Buy milk', $body, 'the task is named as the person knows it');
        self::assertStringContainsString('Buy oat milk', $body);
        self::assertStringContainsString('50%', $body);
        // The date and the internal property nobody edits stay out of the way.
        self::assertStringNotContainsString('X-CUSTOM', $body);
        self::assertStringNotContainsString('NEEDS-ACTION', $body);
    }

    public function testCompleteSaysTheTaskIsDoneAndWhenItWasCompleted(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->render('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1']);
        self::assertIsString($body);
        self::assertStringContainsString('Buy milk', $body);
        self::assertStringContainsString('100%', $body);
        // 2026-10-01T12:00Z is 09:00 in São Paulo.
        self::assertStringContainsString('01/10/2026 09:00', $body);
    }

    public function testDeleteSaysItGoesToTheCalendarTrash(): void {
        Translator::use(new JsonL10n('pt_BR'));
        $body = $this->render('tasks_delete_task', ['calendar' => self::PATH, 'uid' => 't1']);
        self::assertIsString($body);
        self::assertStringContainsString('Buy milk', $body);
        self::assertStringContainsString('lixeira', $body);
        self::assertStringNotContainsString('PERMANENTE', $body);
    }

    public function testAWriteOnASharedCalendarSaysItAffectsOtherPeople(): void {
        $body = $this->render(
            'tasks_complete_task',
            ['calendar' => self::PATH, 'uid' => 't1'],
            'principals/users/bob'
        );
        self::assertIsString($body);
        self::assertStringContainsString('Bob', $body);
    }

    public function testAPlanWithoutWhatTheRendererNeedsFallsBackToTheGenericBody(): void {
        $module = $this->module();
        self::assertInstanceOf(RendersPlans::class, $module);
        self::assertNull($module->renderPlan('tasks_create_task', []));
        self::assertNull($module->renderPlan('tasks_create_task', ['calendar' => ['name' => 'Tasks']]));
        self::assertNull($module->renderPlan('tasks_edit_task', ['calendar' => ['name' => 'Tasks'], 'after' => []]));
        self::assertNull($module->renderPlan('tasks_delete_task', ['calendar' => ['name' => 'Tasks']]));
        self::assertNull($module->renderPlan('tasks_list_tasks', ['calendar' => ['name' => 'Tasks']]));
        // An unknown key in the plan is ignored, not fatal.
        $plan = $module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Buy milk'], 'alice');
        self::assertIsString($module->renderPlan('tasks_create_task', $plan + ['unknown' => true]));
    }

    /** The module is a shared container service: a plan renders the same whatever happened before. */
    public function testTheModuleKeepsNoStateBetweenThePlanAndItsText(): void {
        $module = $this->module();
        $plan = $module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
        // A fresh module with the very same plan renders the very same text: nothing was remembered.
        self::assertSame(
            $module->renderPlan('tasks_complete_task', $plan),
            $this->module()->renderPlan('tasks_complete_task', $plan)
        );
    }

    public function testThePlanCarriesTheTimezoneOfTheAccountThatAsked(): void {
        self::assertSame(
            'America/Sao_Paulo',
            $this->module()->preview('tasks_delete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice')['timezone']
        );
        // An account with no preference of its own still names the zone the server defaults to.
        self::assertNotNull(
            $this->module()->preview('tasks_delete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice')['timezone']
        );
    }

    public function testAPlanWithoutAZoneReadsItsDatesAsUtc(): void {
        $module = $this->module();
        $plan = $module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice');
        $body = $module->renderPlan('tasks_complete_task', ['timezone' => null] + $plan);
        self::assertIsString($body);
        // 12:00 UTC is the moment itself; nobody is promised an hour that no account asked for.
        self::assertStringContainsString('01/10/2026 12:00', $body);
        // A zone this PHP build does not know must not cost the person the whole text.
        self::assertIsString($module->renderPlan('tasks_complete_task', $plan + ['timezone' => 'Mars/Olympus']));
    }

    /** Every write tool of the module renders a plan of its own: none falls back to the generic field list. */
    public function testEveryWriteToolHasAReadablePlan(): void {
        $module = $this->module();
        $this->assertEveryWriteToolHasAReadablePlan($module, [
            'tasks_create_task' => $module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Buy milk'], 'alice'),
            'tasks_edit_task' => $module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Buy bread'], 'alice'),
            'tasks_complete_task' => $module->preview('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice'),
            'tasks_delete_task' => $module->preview('tasks_delete_task', ['calendar' => self::PATH, 'uid' => 't1'], 'alice'),
        ]);
    }

    /** Renders the plan of a real tool call, exactly as the registry asks for it before a write. */
    private function render(string $tool, array $arguments, string $owner = 'principals/users/alice'): ?string {
        $module = $this->module($owner);
        return $module->renderPlan($tool, $module->preview($tool, $arguments, 'alice'));
    }

    /** @param string $owner principal owning the calendar of the plan */
    private function module(string $owner = 'principals/users/alice'): TasksModule {
        $store = $this->createMock(CalendarStore::class);
        $store->method('calendarsForPrincipal')->willReturn([
            [
                'id' => 1,
                'uri' => 'tasks',
                'displayName' => 'Tasks',
                'ownerPrincipal' => $owner,
                'readOnly' => false,
                'components' => ['VTODO'],
                'deleted' => false,
            ],
        ]);
        $row = fn () => ['id' => 1, 'uri' => 't1.ics', 'etag' => '"v1"', 'data' => $this->data, 'deleted' => false];
        $store->method('objectByUid')->willReturnCallback($row);
        $store->method('object')->willReturnCallback($row);
        $tasks = $this->createMock(TaskStore::class);
        $tasks->method('uris')->willReturn(['t1.ics']);
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getDisplayName')->willReturn($uid === 'bob' ? 'Bob' : 'Alice');

            return $user;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        // The account reads dates in São Paulo, so a UTC hour must reach them as their own hour.
        $preferences = new InMemoryConfig();
        $preferences->user['alice']['core']['timezone'] = 'America/Sao_Paulo';
        $config = $preferences->mock($this);

        return new TasksModule(
            new CalendarAccess($store),
            $store,
            $tasks,
            $this->createMock(CalendarDav::class),
            new TaskData(),
            new SharedGuard($users),
            new TrashPolicy($config),
            $time,
            new UserTimezone($config)
        );
    }
}
