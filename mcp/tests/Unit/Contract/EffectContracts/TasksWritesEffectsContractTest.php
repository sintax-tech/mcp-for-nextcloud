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
use OCA\Mcp\Tests\Unit\InMemoryLocks;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Tests\Unit\Access\Visibility\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Tasks writes besides the creation (RE-01 round 2): the edit, the completion and the deletion
 * (into the calendar trash) of a task, and the creation of a subtask under a parent (RELATED-TO), frozen through the
 * endpoint over a fake calendar store, its trash and the CalDAV requests it received. The clock of the module is
 * frozen; the UID the subtask draws is replaced in the transcript only ({@see DavRandomness}), after its shape was
 * asserted on the bytes the store holds. The locking provider excludes, as the server's does with file locking on, so
 * a write that sets a parent can run.
 */
final class TasksWritesEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Path of the task list of alice the flows write to. */
	private const TASKS = '/remote.php/dav/calendars/alice/tasks/';
	/** DTSTAMP of every fixture task. */
	private const FIXTURE_STAMP = '20261001T100000Z';
	/** UIDs of the fixture tasks, by object URI. */
	private const FIXTURES = ['editar.ics' => 'editar', 'concluir.ics' => 'concluir', 'apagar-1.ics' => 'apagar-1',
		'apagar-2.ics' => 'apagar-2', 'projeto.ics' => 'projeto'];
	/** Why no step of these flows can answer PlanChanged. */
	private const NO_FINGERPRINT = 'The Tasks module keeps no plan fingerprint (no PlanState/PlanChanged). The plan '
		. 'returns the ETag of the task and the confirmed call carries it: when the task changed in between, the ETag no '
		. 'longer matches and the call is refused as a conflict, without a new plan (asserted outside the golden); the '
		. 'CalDAV write itself carries the read ETag as If-Match.';
	/** Why no step of these flows can hide its target. */
	private const NO_VISIBILITY = 'Tasks are CalDAV objects, not Files nodes: no system tag or excluded folder can hide '
		. 'a task list or a task from MCP (VisibilityGuard and FilesAccessGuard do not apply). What narrows the reachable '
		. 'lists is the administrator resource scope and the native ACL, not visibility.';

	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;
	private DavRandomness $random;
	private EffectsGate $gate;

	/** @return void builds the task list with its fixture tasks and the endpoint */
	protected function setUp(): void {
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'tasks', self::ALICE, ['name' => 'Tarefas', 'components' => ['VTODO']]);
		foreach (self::FIXTURES as $uri => $uid) {
			$this->store->addObject(1, $uri, self::task($uid, ucfirst(str_replace('-', ' ', $uid))));
		}
		$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
		$this->random = new DavRandomness($this->store, array_values(self::FIXTURES),
			[CalendarEffectsWiring::STAMP, self::FIXTURE_STAMP], DavRandomness::HEX_128);
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
		return new TasksModule(ScopeStub::access($this->store), $this->store, $tasks, $this->dav, new TaskData(),
			new SharedGuard(FakeUsers::manager($this, ['alice' => 'Alice'])), new TrashPolicy($this->gate->config()), $time,
			OutputReferenceFilters::inactive($this), new UserTimezone($this->gate->config()), new InMemoryLocks());
	}

	/** @return void tasks_edit_task of a task of alice: plan, strict confirm, the CalDAV update and every refusal */
	public function testEditOfATask(): void {
		$flow = $this->flow('edit-task', 'tasks_edit_task', 'edit',
			fn (): array => ['calendar' => self::TASKS, 'uid' => 'editar', 'summary' => 'Revisar contrato', 'priority' => 2]);
		(new FlowRunner($this, $this->gate, 'tasks'))->run($flow);
		self::assertStringContainsString("SUMMARY:Revisar contrato\r\n", $this->store->objects[1]['editar.ics']['data']);
		self::assertStringContainsString("X-CUSTOM:keep\r\n", $this->store->objects[1]['editar.ics']['data'], 'unknown properties survive');
		self::assertSame(['update tasks/editar.ics'], $this->dav->log);
	}

	/** @return void tasks_complete_task: plan, strict confirm, the completed task and every refusal */
	public function testCompletionOfATask(): void {
		$flow = $this->flow('complete-task', 'tasks_complete_task', 'edit', fn (): array => ['calendar' => self::TASKS, 'uid' => 'concluir']);
		(new FlowRunner($this, $this->gate, 'tasks'))->run($flow);
		$data = $this->store->objects[1]['concluir.ics']['data'];
		self::assertStringContainsString("STATUS:COMPLETED\r\n", $data);
		self::assertStringContainsString("PERCENT-COMPLETE:100\r\n", $data);
		self::assertStringContainsString("COMPLETED:" . CalendarEffectsWiring::STAMP . "\r\n", $data, 'completed at the module clock');
		self::assertSame(['update tasks/concluir.ics'], $this->dav->log);
	}

	/**
	 * tasks_delete_task into the calendar trash: plan, strict confirm, the CalDAV delete and every refusal. After the
	 * confirmed deletion each refusal targets the other task, so it is asked with a confirmation that would delete.
	 *
	 * @return void
	 */
	public function testDeletionOfATaskGoesToTheTrash(): void {
		$flow = $this->flow('delete-task', 'tasks_delete_task', 'delete', fn (): array => ['calendar' => self::TASKS,
			'uid' => isset($this->store->objects[1]['apagar-1.ics']) ? 'apagar-1' : 'apagar-2']);
		(new FlowRunner($this, $this->gate, 'tasks'))->run($flow);
		self::assertSame(['delete tasks/apagar-1.ics'], $this->dav->log, 'one CalDAV delete, of the confirmed task');
		self::assertTrue($this->store->objects[1]['apagar-1-deleted.ics']['deleted'] ?? false, 'the task is in the trash');
		self::assertArrayHasKey('apagar-2.ics', $this->store->objects[1], 'only the confirmed task is deleted');
		// The receipt of a confirmed deletion, read directly: deleted and recoverable, after a CalDAV delete.
		$arguments = ['calendar' => self::TASKS, 'uid' => 'apagar-2'];
		$body = $this->gate->call('tasks_delete_task', $flow->confirmed($this->gate->call('tasks_delete_task', $arguments)['body']))['body'];
		self::assertSame(['uid' => 'apagar-2', 'calendar' => self::TASKS, 'deleted' => true, 'recoverable' => true],
			json_decode($body->result->content[0]->text ?? 'null', true), json_encode($body));
		self::assertSame('delete tasks/apagar-2.ics', $this->dav->log[1] ?? null, 'the receipt follows a CalDAV delete');
	}

	/** @return void with calendar retention at zero a deletion would be permanent: plan and confirmation are refused */
	public function testDeletionWithoutCalendarTrashIsRefused(): void {
		$this->gate->data()->app['dav']['calendarRetentionObligation'] = '0';
		$before = $this->state();
		foreach ([[], ['confirm' => true]] as $extra) {
			$body = $this->gate->call('tasks_delete_task', ['calendar' => self::TASKS, 'uid' => 'apagar-1'] + $extra)['body'];
			self::assertTrue($body->result->isError ?? false, 'a permanent deletion must be refused: ' . json_encode($body));
			self::assertEquals($before, $this->state(), 'nothing is deleted');
		}
	}

	/**
	 * tasks_create_task with a parent: the new subtask carries RELATED-TO the parent, and the parent is untouched.
	 *
	 * @return void
	 */
	public function testCreationOfASubtask(): void {
		$flow = (new CriticalFlow('create-subtask', 'tasks_create_task', 'tasks', 'create',
			['calendar' => self::TASKS, 'summary' => 'Levantar requisitos', 'parent' => 'projeto'], fn (): array => $this->state()))
			->withoutPlanChanged('A creation has no existing object to change between the plan and the confirmation, and '
				. 'the Tasks module keeps no plan fingerprint (no PlanState/PlanChanged): the confirmed call checks the parent '
				. 'again under the hierarchy lock, and a parent gone since the plan is refused (asserted outside the golden).')
			->withoutVisibility(self::NO_VISIBILITY)
			->normalizing(fn (): array => $this->random->literals());
		$parent = $this->store->objects[1]['projeto.ics'];
		(new FlowRunner($this, $this->gate, 'tasks'))->run($flow);
		$created = array_values(array_diff_key($this->store->objects[1], self::FIXTURES));
		self::assertCount(1, $created, 'one subtask created');
		self::assertStringContainsString("RELATED-TO;RELTYPE=PARENT:projeto\r\n", $created[0]['data'], 'the subtask points at its parent');
		self::assertSame($parent, $this->store->objects[1]['projeto.ics'], 'the parent is not rewritten');
		$this->random->assertShapes($this);
	}

	/** @return void a subtask whose parent went to the trash after the plan is refused and writes nothing */
	public function testSubtaskOfAParentGoneSinceThePlanIsRefused(): void {
		$arguments = ['calendar' => self::TASKS, 'summary' => 'Levantar requisitos', 'parent' => 'projeto'];
		$plan = $this->gate->call('tasks_create_task', $arguments)['body'];
		self::assertTrue($plan->result->structuredContent->requiresConfirmation ?? false, json_encode($plan));
		$this->dav->delete(EffectsGate::USER, 'tasks', 'projeto.ics');
		$before = $this->state();
		$body = $this->gate->call('tasks_create_task', $arguments + ['confirm' => true])['body'];
		self::assertTrue($body->result->isError ?? false, 'a parent gone since the plan must be refused: ' . json_encode($body));
		self::assertEquals($before, $this->state(), 'nothing is created');
	}

	/** @return void a write confirmed with the ETag of an older version of the task is refused and writes nothing */
	public function testWriteWithAStaleEtagIsRefused(): void {
		foreach (['tasks_edit_task' => ['summary' => 'Outro'], 'tasks_complete_task' => [], 'tasks_delete_task' => []] as $tool => $fields) {
			$uid = $tool === 'tasks_delete_task' ? 'apagar-1' : 'editar';
			$arguments = ['calendar' => self::TASKS, 'uid' => $uid] + $fields;
			$plan = $this->gate->call($tool, $arguments)['body'];
			$stale = $arguments + ['confirm' => true, 'etag' => $plan->result->structuredContent->etag];
			// Another client changes the task after the plan.
			$this->store->objects[1][$uid . '.ics']['etag'] = '"outra-versao-' . $tool . '"';
			$before = $this->state();
			$body = $this->gate->call($tool, $stale)['body'];
			self::assertTrue($body->result->isError ?? false, $tool . ': a stale ETag must be refused: ' . json_encode($body));
			self::assertEquals($before, $this->state(), $tool . ': a stale ETag must write nothing');
		}
	}

	/**
	 * @param string $name flow name
	 * @param string $tool tool under test
	 * @param string $operation grant operation revoked by the no-grant step
	 * @param \Closure(): array<string, mixed> $arguments plan arguments as of now
	 * @return CriticalFlow a write on an existing task, confirmed with the ETag of its plan
	 */
	private function flow(string $name, string $tool, string $operation, \Closure $arguments): CriticalFlow {
		return (new CriticalFlow($name, $tool, 'tasks', $operation, $arguments, fn (): array => $this->state()))
			->confirmingWith(fn (mixed $plan, array $plain): array => $plain
				+ ['confirm' => true, 'etag' => $plan->result->structuredContent->etag ?? null])
			->withoutPlanChanged(self::NO_FINGERPRINT)
			->withoutVisibility(self::NO_VISIBILITY)
			->normalizing(fn (): array => $this->random->literals());
	}

	/**
	 * @return array{objects: array<int, list<string>>, dav: list<string>, trash: list<string>} live iCalendar text per
	 *     calendar, the dispatched requests, and the object names in the calendar trash
	 */
	private function state(): array {
		$trash = [];
		foreach ($this->store->objects as $rows) {
			foreach ($rows as $uri => $row) {
				if ($row['deleted']) {
					$trash[] = $uri;
				}
			}
		}
		return CalendarEffectsWiring::state($this->store, $this->dav) + ['trash' => $trash];
	}

	/**
	 * @param string $uid task UID
	 * @param string $summary task title
	 * @return string the iCalendar text of a simple open task, with a property no tool knows
	 */
	private static function task(string $uid, string $summary): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:$uid\r\nDTSTAMP:" . self::FIXTURE_STAMP
			. "\r\nSUMMARY:$summary\r\nSTATUS:NEEDS-ACTION\r\nX-CUSTOM:keep\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}
}
