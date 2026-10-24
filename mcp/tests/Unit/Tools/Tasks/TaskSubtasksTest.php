<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Tests\Unit\Tools\Tasks\Handler\TaskListingCase;
use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\Tasks\TaskHierarchy;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Verifies reading, writing and filtering subtasks (VTODO RELATED-TO parent links) without widening visibility.
 *
 * The cases come from PR #18 by Marc-Olivier Turgeon-Ferland (@mturgeonferland); the plan text and the depth
 * constant follow the adaptation (title with UID, TaskHierarchy::MAX_DEPTH).
 */
final class TaskSubtasksTest extends TaskListingCase {
	/**
	 * A task without a parent relation reads with parent null.
	 *
	 * @return void
	 */
	public function testATopLevelTaskHasNoParent(): void {
		self::assertNull($this->read('t1')['parent']);
	}

	/**
	 * PARENT, the RFC 5545 default, is read with or without RELTYPE; a sibling is not a parent.
	 *
	 * @return void
	 */
	public function testTheParentIsReadWithOrWithoutTheDefaultRelationType(): void {
		$this->tasks['t2'] = $this->task('t2', 'Explicit', "RELATED-TO;RELTYPE=PARENT:t1\r\n");
		$this->tasks['t3'] = $this->task('t3', 'Implicit', "RELATED-TO:t1\r\n");
		$this->tasks['t4'] = $this->task('t4', 'Sibling', "RELATED-TO;RELTYPE=SIBLING:t1\r\n");
		self::assertSame('t1', $this->read('t2')['parent']);
		self::assertSame('t1', $this->read('t3')['parent'], 'PARENT is the RFC 5545 default relation type');
		self::assertNull($this->read('t4')['parent'], 'a sibling is not a parent');
	}

	/**
	 * Create writes RELTYPE=PARENT and the plan names the parent by title and UID.
	 *
	 * @return void
	 */
	public function testCreateWritesTheParentLinkAndThePlanNamesTheParentByTitle(): void {
		$args = ['calendar' => self::PATH, 'summary' => 'Step', 'parent' => 't1'];
		$plan = $this->module->preview('tasks_create_task', $args, 'alice');
		self::assertSame('t1', $plan['after']['parent']);
		self::assertSame(['t1' => 'Work'], $plan['parents']);
		self::assertStringContainsString('- Parent task: **Work** ("t1")', (string) $this->module->renderPlan('tasks_create_task', $plan));
		$this->dav->expects(self::once())->method('put')->willReturnCallback(
			function ($user, $calendar, $uri, $data) {
				self::assertStringContainsString("RELATED-TO;RELTYPE=PARENT:t1\r\n", $data);
				$this->tasks[basename($uri, '.ics')] = $data;
				return new DavResult(201);
			}
		);
		self::assertSame('t1', $this->json($this->module->call('tasks_create_task', $args + ['confirm' => true], 'alice'))['parent']);
	}

	/**
	 * A parent that does not exist is refused before any write.
	 *
	 * @return void
	 */
	public function testAMissingParentIsRefusedBeforeAnyWrite(): void {
		$this->dav->expects(self::never())->method('put');
		$this->expectException(ToolFailure::class);
		$this->module->call('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Step', 'parent' => 'nope', 'confirm' => true], 'alice');
	}

	/**
	 * A private parent in a shared calendar reads exactly like a missing one.
	 *
	 * @return void
	 */
	public function testAProtectedParentOfASharedCalendarReadsAsMissing(): void {
		$this->owner = 'principals/users/bob';
		$this->tasks['secret'] = $this->task('secret', 'Secret', "CLASS:PRIVATE\r\n");
		$this->dav->expects(self::never())->method('update');
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage('parent task was not found');
		$this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 'secret'], 'alice');
	}

	/**
	 * Edit replaces only the parent link, keeping other relations and unknown properties.
	 *
	 * @return void
	 */
	public function testEditReplacesOnlyTheParentLinkAndKeepsOtherRelations(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		$this->tasks['t3'] = $this->task('t3', 'Other project');
		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO;RELTYPE=PARENT:t3\r\nRELATED-TO;RELTYPE=SIBLING:t9\r\n");
		$args = ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2'];
		$plan = $this->module->preview('tasks_edit_task', $args, 'alice');
		self::assertSame(['t3' => 'Other project', 't2' => 'Project'], $plan['parents']);
		self::assertStringContainsString(
			'- Parent task: **Other project** ("t3") → **Project** ("t2")',
			(string) $this->module->renderPlan('tasks_edit_task', $plan)
		);
		$this->expectUpdate(
			function (string $data) {
				self::assertStringContainsString("RELATED-TO;RELTYPE=PARENT:t2\r\n", $data);
				self::assertStringNotContainsString(':t3', $data);
				self::assertStringContainsString("RELATED-TO;RELTYPE=SIBLING:t9\r\n", $data);
				self::assertStringContainsString('X-CUSTOM:keep', $data);
			}
		);
		self::assertSame('t2', $this->json($this->module->call('tasks_edit_task', $args + ['confirm' => true], 'alice'))['parent']);
	}

	/**
	 * Null removes every parent relation and keeps the others.
	 *
	 * @return void
	 */
	public function testEditWithNullMakesTheTaskTopLevel(): void {
		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO:t3\r\nRELATED-TO;RELTYPE=CHILD:t8\r\n");
		$this->expectUpdate(
			function (string $data) {
				self::assertStringNotContainsString(':t3', $data);
				self::assertStringContainsString("RELATED-TO;RELTYPE=CHILD:t8\r\n", $data);
			}
		);
		$args = ['calendar' => self::PATH, 'uid' => 't1', 'parent' => null];
		self::assertNull($this->json($this->module->call('tasks_edit_task', $args + ['confirm' => true], 'alice'))['parent']);
	}

	/**
	 * An edit without parent leaves the parent link as it is.
	 *
	 * @return void
	 */
	public function testEditWithoutParentLeavesTheLinkUntouched(): void {
		$this->tasks['t1'] = $this->task('t1', 'Work', "RELATED-TO;RELTYPE=PARENT:t3\r\n");
		$this->expectUpdate(fn (string $data) => self::assertStringContainsString("RELATED-TO;RELTYPE=PARENT:t3\r\n", $data));
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'summary' => 'Renamed', 'confirm' => true], 'alice');
	}

	/**
	 * A task cannot be its own parent.
	 *
	 * @return void
	 */
	public function testATaskCannotBeItsOwnParent(): void {
		$this->dav->expects(self::never())->method('update');
		$this->expectException(InvalidArgumentException::class);
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't1', 'confirm' => true], 'alice');
	}

	/**
	 * A task cannot go under one of its own descendants.
	 *
	 * @return void
	 */
	public function testATaskCannotBecomeASubtaskOfItsOwnDescendant(): void {
		$this->tasks['t2'] = $this->task('t2', 'Child', "RELATED-TO:t1\r\n");
		$this->tasks['t3'] = $this->task('t3', 'Grandchild', "RELATED-TO:t2\r\n");
		$this->dav->expects(self::never())->method('update');
		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage('subtask of one of its own subtasks');
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't3', 'confirm' => true], 'alice');
	}

	/**
	 * A parent beyond the ceiling is refused.
	 *
	 * @return void
	 */
	public function testAnOverlyDeepHierarchyIsRefused(): void {
		$this->tasks = ['n0' => $this->task('n0', 'Root')];
		for ($i = 1; $i <= TaskHierarchy::MAX_DEPTH + 1; $i++) {
			$this->tasks['n' . $i] = $this->task('n' . $i, 'Level ' . $i, 'RELATED-TO:n' . ($i - 1) . "\r\n");
		}
		$this->expectException(ToolFailure::class);
		$this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Deep', 'parent' => 'n' . (TaskHierarchy::MAX_DEPTH + 1)], 'alice');
	}

	/**
	 * Parent alone is a valid edit.
	 *
	 * @return void
	 */
	public function testParentAloneIsAnEdit(): void {
		$this->tasks['t2'] = $this->task('t2', 'Project');
		self::assertSame('t2', $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'parent' => 't2'], 'alice')['after']['parent']);
	}

	/**
	 * The parent filter lists direct subtasks only, before offset and limit.
	 *
	 * @return void
	 */
	public function testTheParentFilterListsDirectSubtasksOnlyAndPagesOverThem(): void {
		$this->tasks['a'] = $this->task('a', 'A', "RELATED-TO:t1\r\n");
		$this->tasks['b'] = $this->task('b', 'B', "RELATED-TO;RELTYPE=PARENT:t1\r\n");
		$this->tasks['deep'] = $this->task('deep', 'Deep', "RELATED-TO:a\r\n");
		$this->tasks['c'] = $this->task('c', 'C', "RELATED-TO:t1\r\n");
		$first = $this->list(['parent' => 't1', 'limit' => 2]);
		self::assertSame(['a', 'b'], array_column($first['tasks'], 'uid'));
		self::assertSame(2, $first['nextOffset']);
		self::assertSame(['c'], array_column($this->list(['parent' => 't1', 'offset' => 2])['tasks'], 'uid'));
		self::assertSame(['deep'], array_column($this->list(['parent' => 'a'])['tasks'], 'uid'));
	}

	/**
	 * A parent filter without match is not reported as a category miss.
	 *
	 * @return void
	 */
	public function testAParentFilterWithoutMatchIsNotReportedAsACategoryMiss(): void {
		$this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:Home\r\n");
		$page = $this->list(['parent' => 'nobody', 'category' => 'home']);
		self::assertSame([], $page['tasks']);
		self::assertArrayNotHasKey('note', $page);
	}

	/**
	 * Protected subtasks of a shared calendar are not listed.
	 *
	 * @return void
	 */
	public function testProtectedSubtasksOfASharedCalendarAreNotListed(): void {
		$this->owner = 'principals/users/bob';
		$this->tasks['hidden'] = $this->task('hidden', 'Hidden', "CLASS:CONFIDENTIAL\r\nRELATED-TO:t1\r\n");
		$this->tasks['open'] = $this->task('open', 'Open', "RELATED-TO:t1\r\n");
		self::assertSame(['open'], array_column($this->list(['parent' => 't1'])['tasks'], 'uid'));
	}
}
