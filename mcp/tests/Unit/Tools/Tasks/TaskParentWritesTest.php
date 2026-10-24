<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use OCA\Mcp\Tests\Unit\Tools\Tasks\Handler\TaskListingCase;
use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\PlanText;
use OCA\Mcp\Tools\Tasks\TaskHierarchyLock;
use OCA\Mcp\Tools\Tasks\TaskHierarchyMessages;
use OCA\Mcp\Tools\ToolFailure;

/**
 * The create and edit writes with `parent` through the module: a plan that tells parents apart, the check redone
 * at confirmation on the state of that moment, the hierarchy lock around confirmed parent writes only, the warning
 * when a loop appears after the write, the schema, and no cascade to subtasks.
 */
final class TaskParentWritesTest extends TaskListingCase {
	/**
	 * Two parents with the same title, or titles that read alike once cut, are told apart by UID in the plan.
	 *
	 * @return void
	 */
	public function testParentsThatReadAlikeAreToldApartInThePlan(): void {
		$long = str_repeat('Projeto ', 40);
		$this->tasks['a'] = $this->task('a', 'Projeto');
		$this->tasks['b'] = $this->task('b', 'Projeto');
		$this->tasks['c'] = $this->task('c', $long . 'one');
		$this->tasks['d'] = $this->task('d', $long . 'two');
		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO:a\r\n");
		$plan = $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 'b'], 'alice');
		$this->assertStringContainsString('- Parent task: **Projeto** ("a") → **Projeto** ("b")', (string)$this->module->renderPlan('tasks_edit_task', $plan));

		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO:c\r\n");
		$plan = $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 'd'], 'alice');
		$text = (string)$this->module->renderPlan('tasks_edit_task', $plan);
		$this->assertStringContainsString('("c") → ', $text);
		$this->assertStringContainsString('("d")', $text);
	}

	/**
	 * A UID with Markdown or quotes is shown escaped and inert, and an unreadable parent shows its UID alone.
	 *
	 * @return void
	 */
	public function testAParentUidIsShownEscaped(): void {
		$this->tasks['x*y"z'] = $this->task('x*y"z', 'Odd');
		$plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Step', 'parent' => 'x*y"z'], 'alice');
		$text = (string)$this->module->renderPlan('tasks_create_task', $plan);
		$this->assertStringContainsString('**Odd** (' . PlanText::quoted('x*y"z') . ')', $text);
		$this->assertStringNotContainsString('x*y', $text, 'the asterisk is escaped');

		$plan['parents'] = [];
		$this->assertStringContainsString('- Parent task: ' . PlanText::quoted('x*y"z') . "\n", (string)$this->module->renderPlan('tasks_create_task', $plan) . "\n");
	}

	/**
	 * A plan that was valid in the preview is refused at confirmation when the hierarchy changed in between.
	 *
	 * @return void
	 */
	public function testTheConfirmationChecksTheHierarchyAgain(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$args = ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2'];
		$this->assertSame('t2', $this->module->preview('tasks_edit_task', $args, 'alice')['after']['parent']);

		$this->tasks['t2'] = $this->task('t2', 'Project', "RELATED-TO:t1\r\n");
		$this->dav->expects(self::never())->method('update');
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(TaskHierarchyMessages::loop());
		$this->module->call('tasks_edit_task', $args + ['confirm' => true], 'alice');
	}

	/**
	 * A confirmed write that sets a parent holds the lock of its calendar during the check and the write, and
	 * releases it afterwards, even when the write fails.
	 *
	 * @return void
	 */
	public function testAConfirmedParentWriteHoldsTheHierarchyLock(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$held = null;
		$this->expectUpdate(function () use (&$held): void {
			$held = array_keys($this->locks->held);
		});
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2', 'confirm' => true], 'alice');
		$this->assertSame([TaskHierarchyLock::KEY_PREFIX . '1'], $held);
		$this->assertSame([], $this->locks->held);
	}

	/**
	 * A held lock refuses the confirmed parent write before anything is written.
	 *
	 * @return void
	 */
	public function testAHeldLockRefusesTheWrite(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->locks->held[TaskHierarchyLock::KEY_PREFIX . '1'] = true;
		$this->dav->expects(self::never())->method('update');
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(TaskHierarchyMessages::busy());
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2', 'confirm' => true], 'alice');
	}

	/**
	 * Previews, writes that clear or keep the parent and writes of other tools never take the lock.
	 *
	 * @return void
	 */
	public function testOtherWritesDoNotTakeTheLock(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2'], 'alice');
		$this->dav->method('update')->willReturnCallback(function ($user, $calendar, $uri, $etag, $data) {
			$this->tasks[basename($uri, '.ics')] = $data;
			return new DavResult(204);
		});
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => null, 'confirm' => true], 'alice');
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Renamed', 'confirm' => true], 'alice');
		$this->module->call('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1', 'confirm' => true], 'alice');
		$this->assertSame([], $this->locks->acquired);
	}

	/**
	 * When the chain read back after the write loops through the task, the answer carries a warning.
	 *
	 * @return void
	 */
	public function testALoopFoundAfterTheWriteIsReported(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->expectUpdate(function (): void {
			// Another client links the new parent under the task between the check and the read back.
			$this->tasks['t2'] = $this->task('t2', 'Project', "RELATED-TO:t1\r\n");
		});
		$answer = $this->json($this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2', 'confirm' => true], 'alice'));
		$this->assertSame('t2', $answer['parent']);
		$this->assertSame(TaskHierarchyMessages::loopAfterWrite(), $answer['warning']);
	}

	/**
	 * Completing or deleting a parent writes the parent only: its subtasks are not touched.
	 *
	 * @return void
	 */
	public function testCompletingAParentDoesNotCascade(): void {
		$this->tasks['child'] = $this->task('child', 'Child', "RELATED-TO:t1\r\n");
		$child = $this->tasks['child'];
		$this->expectUpdate(fn (string $data) => self::assertStringContainsString('UID:t1', $data));
		$this->module->call('tasks_complete_task', ['calendar' => self::PATH, 'uid' => 't1', 'confirm' => true], 'alice');
		$this->assertSame($child, $this->tasks['child']);
	}

	/**
	 * A recurring series can be read and used as a parent, while changing the series stays refused.
	 *
	 * @return void
	 */
	public function testARecurringParentIsAcceptedWithoutChangingTheSeries(): void {
		$this->tasks['series'] = $this->task('series', 'Weekly', "DTSTART:20261001T100000Z\r\nRRULE:FREQ=WEEKLY\r\n");
		$plan = $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 'series'], 'alice');
		$this->assertSame('series', $plan['after']['parent']);
		$this->expectException(ToolFailure::class);
		$this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 'series', 'parent' => 't1'], 'alice');
	}

	/**
	 * An ETag that no longer matches is refused before the hierarchy is read.
	 *
	 * @return void
	 */
	public function testAStaleEtagIsRefusedBeforeTheHierarchy(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->dav->expects(self::never())->method('update');
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessageMatches('/etag/i');
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2', 'etag' => '"old"', 'confirm' => true], 'alice');
	}

	/**
	 * Several parent relations read as the first one; an edit leaves exactly one, in any RELTYPE case, and keeps
	 * every other relation with its parameters.
	 *
	 * @return void
	 */
	public function testSeveralRelationsAreNormalizedToOneParent(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO;RELTYPE=parent;X-NOTE=a:p1\r\nRELATED-TO:p2\r\n"
			. "RELATED-TO;RELTYPE=X-DEPENDS-ON;X-NOTE=b:d1\r\nRELATED-TO;RELTYPE=SIBLING:s1\r\n");
		$this->assertSame('p1', $this->read('t1')['parent']);
		$this->expectUpdate(function (string $data): void {
			self::assertSame(1, substr_count($data, 'RELTYPE=PARENT'));
			self::assertStringContainsString("RELATED-TO;RELTYPE=PARENT:t2\r\n", $data);
			self::assertStringNotContainsString(':p1', $data);
			self::assertStringNotContainsString(':p2', $data);
			self::assertStringContainsString("RELATED-TO;RELTYPE=X-DEPENDS-ON;X-NOTE=b:d1\r\n", $data);
			self::assertStringContainsString("RELATED-TO;RELTYPE=SIBLING:s1\r\n", $data);
		});
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2', 'confirm' => true], 'alice');
	}

	/**
	 * With a locking provider that excludes nothing, or none at all, a confirmed write that sets a parent is refused
	 * before any DAV call; its preview, a write that makes the task top-level and an ordinary edit still work.
	 *
	 * @return void
	 */
	public function testWithoutEffectiveLockingAParentWriteIsRefusedBeforeDav(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$args = ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2'];
		$updates = 0;
		$this->dav->method('update')->willReturnCallback(function ($user, $calendar, $uri, $etag, $data) use (&$updates) {
			$updates++;
			$this->tasks[basename($uri, '.ics')] = $data;
			return new DavResult(204);
		});
		$this->dav->expects(self::never())->method('put');
		foreach (['ineffective' => new IneffectiveLocks(), 'absent' => null] as $case => $locks) {
			$module = $this->withLocks($locks);
			$this->assertSame('t2', $module->preview('tasks_edit_task', $args, 'alice')['after']['parent'], $case);
			foreach ([$args, ['calendar' => self::PATH, 'summary' => 'Step', 'parent' => 't2']] as $write) {
				try {
					$module->call(isset($write['uid']) ? 'tasks_edit_task' : 'tasks_create_task', $write + ['confirm' => true], 'alice');
					$this->fail("$case: a parent write ran without exclusion");
				} catch (ToolFailure $e) {
					$this->assertSame(TaskHierarchyMessages::lockingUnavailable(), $e->getMessage(), $case);
				}
			}
			$this->assertSame(0, $updates, "$case: no DAV call");
			if ($locks instanceof IneffectiveLocks) {
				$this->assertSame(['acquire:' . TaskHierarchyLock::KEY_PREFIX . '1', 'release:' . TaskHierarchyLock::KEY_PREFIX . '1',
					'acquire:' . TaskHierarchyLock::KEY_PREFIX . '1', 'release:' . TaskHierarchyLock::KEY_PREFIX . '1'], $locks->calls);
			}
		}
		$module = $this->withLocks(new IneffectiveLocks());
		$module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => null, 'confirm' => true], 'alice');
		$module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Renamed', 'confirm' => true], 'alice');
		$this->assertSame(2, $updates);
	}

	/**
	 * The schema: create and edit take a UID or null; the listing filter takes a UID only.
	 *
	 * @return void
	 */
	public function testTheSchemaOfParent(): void {
		$tools = array_column($this->module->definitions(), null, 'name');
		foreach (['tasks_create_task', 'tasks_edit_task'] as $tool) {
			$this->assertSame(['string', 'null'], ((array)$tools[$tool]['inputSchema']['properties'])['parent']['type']);
			$this->assertNotContains('parent', $tools[$tool]['inputSchema']['required'] ?? []);
		}
		$this->assertSame('string', ((array)$tools['tasks_list_tasks']['inputSchema']['properties'])['parent']['type']);
		$this->assertArrayNotHasKey('parent', (array)$tools['tasks_read_task']['inputSchema']['properties']);
	}
}
