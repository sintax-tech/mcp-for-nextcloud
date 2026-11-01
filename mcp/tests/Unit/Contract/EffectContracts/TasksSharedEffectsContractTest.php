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
use OCA\Mcp\Tests\Unit\Tools\Common\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/** Shared task-list writes require confirm_shared even with a fresh ETag and a valid confirm. */
final class TasksSharedEffectsContractTest extends TestCase {
	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;
	private EffectsGate $gate;

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

	/** @return void edit, completion and deletion all freeze the additional shared confirmation barrier */
	public function testSharedTaskWrites(): void {
		foreach (['tasks_edit_task' => ['summary' => 'Changed'], 'tasks_complete_task' => [], 'tasks_delete_task' => []] as $tool => $extra) {
			$this->gate = new EffectsGate($this);
			$this->store = new FakeCalendarStore();
			$this->store->addCalendar('principals/users/alice', 1, 'team', 'principals/users/pedro', ['components' => ['VTODO'], 'name' => 'Shared tasks']);
			$this->store->addObject(1, 'task.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:task\r\nDTSTAMP:20260301T100000Z\r\nSUMMARY:Original\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
			$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
			$this->gate->serve(fn (): array => [$this->module()]);
			$t = new EffectTranscript($this, $tool . '.shared');
			$args = ['calendar' => '/remote.php/dav/calendars/alice/team/', 'uid' => 'task'] + $extra;
			$plan = $this->gate->call($tool, $args)['body'];
			$this->assertTrue($plan->result->structuredContent->requiresConfirmation ?? false, json_encode($plan));
			$args += ['confirm' => true, 'etag' => $plan->result->structuredContent->etag];
			$before = $this->store->objects;
			$out = $this->gate->call($tool, $args);
			$t->record('shared-unconfirmed', $args, $out, $this->store->objects);
			$this->assertTrue($out['body']->result->isError ?? false, json_encode($out));
			$this->assertSame($before, $this->store->objects);
			$args['confirm_shared'] = true;
			$out = $this->gate->call($tool, $args);
			$t->record('shared-confirmed', $args, $out, $this->store->objects);
			$this->assertFalse($out['body']->result->isError ?? false, json_encode($out));
			$this->assertNotSame($before, $this->store->objects);
			$t->assertGolden();
		}
	}
}
