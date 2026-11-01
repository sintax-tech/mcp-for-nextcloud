<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use DateTimeImmutable;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Tests\Unit\Tools\Common\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Tasks\IneffectiveLocks;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Tasks module (RE-01): the creation of a task, frozen through the endpoint with a fake
 * calendar store as the observable effect and the CalDAV requests it received.
 */
final class TasksEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Path of the task list of alice the flow writes to. */
	private const TASKS = '/remote.php/dav/calendars/alice/tasks/';

	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;
	private DavRandomness $random;
	private EffectsGate $gate;

	/** @return void builds the task list and the endpoint */
	protected function setUp(): void {
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'tasks', self::ALICE, ['name' => 'Tarefas', 'components' => ['VTODO']]);
		$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
		$this->random = new DavRandomness($this->store, [], [CalendarEffectsWiring::STAMP], DavRandomness::HEX_128);
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return TasksModule the module of one request, every collaborator present as the container autowires it */
	private function module(): TasksModule {
		$tasks = $this->createMock(TaskStore::class);
		$tasks->method('uris')->willReturnCallback(fn (int $id): array => array_keys(array_filter($this->store->objects[$id] ?? [],
			static fn (array $row): bool => !$row['deleted'])));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturnCallback(static fn (): DateTimeImmutable => new DateTimeImmutable(CalendarEffectsWiring::NOW));
		// The provider the server hands out with filelocking.enabled false; a create without parent takes no lock.
		return new TasksModule(ScopeStub::access($this->store), $this->store, $tasks, $this->dav, new TaskData(),
			new SharedGuard(FakeUsers::manager($this, ['alice' => 'Alice'])), new TrashPolicy($this->gate->config()), $time,
			OutputReferenceFilters::inactive($this), new UserTimezone($this->gate->config()), new IneffectiveLocks());
	}

	/** @return void tasks_create_task in a task list of alice: plan, strict confirm, effect and every refusal */
	public function testCreationOfATask(): void {
		$flow = (new CriticalFlow('create-task', 'tasks_create_task', 'tasks', 'create',
			['calendar' => self::TASKS, 'summary' => 'Enviar relatório', 'description' => 'Fechamento de março',
				'due' => '2026-03-20T18:00:00-03:00', 'priority' => 1],
			fn (): array => CalendarEffectsWiring::state($this->store, $this->dav)))
			->withoutPlanChanged('A creation has no existing object to change between the plan and the confirmation, '
				. 'and the Tasks module keeps no plan fingerprint (no PlanState/PlanChanged): the confirmed call is '
				. 'prepared again from the arguments and writes a new task with a new UID. Edits rely on the ETag instead.')
			->withoutVisibility('Tasks are CalDAV objects, not Files nodes: no system tag or excluded folder can hide a '
				. 'task list or a task from MCP (VisibilityGuard and FilesAccessGuard do not apply). What narrows the '
				. 'reachable lists is the administrator resource scope and the native ACL, not visibility.')
			->normalizing(fn (): array => $this->random->literals());
		(new FlowRunner($this, $this->gate, 'tasks'))->run($flow);
		$this->assertSecondCreationDrawsItsOwnUid($flow);
	}

	/**
	 * A second confirmed creation is a second object with its own drawn UID; the shape of every drawn UID and stamp is
	 * checked on the bytes the store holds, before the transcript replaced them.
	 *
	 * @param CriticalFlow $flow the creation flow, already run
	 * @return void
	 */
	private function assertSecondCreationDrawsItsOwnUid(CriticalFlow $flow): void {
		$body = $this->gate->call($flow->tool, $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']))['body'];
		$this->assertFalse($body->result->isError ?? false, 'a second creation must succeed: ' . json_encode($body));
		$this->assertCount(2, $this->store->objects[1], 'two creations are two objects');
		$this->random->assertShapes($this);
	}
}
