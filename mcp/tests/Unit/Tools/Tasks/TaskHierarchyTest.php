<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskHierarchy;
use OCA\Mcp\Tools\Tasks\TaskHierarchyMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The 64-level ceiling as the MCP writes observe it, the subtree a reparenting moves, loops new and old, broken
 * hierarchies and the parent rules (same calendar, VTODO, live, readable), all without naming a hidden task.
 */
final class TaskHierarchyTest extends TestCase {
	/** Calendar of the tests. */
	private const ID = 1;

	/** Calendars in memory. */
	private HierarchyCalendars $calendars;

	/**
	 * Starts from empty calendars.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calendars = new HierarchyCalendars();
	}

	/**
	 * A new task goes at level 64 under a parent at level 63, and is refused under a parent at level 64 or 65.
	 *
	 * @return void
	 */
	public function testANewTaskStopsAtLevelSixtyFour(): void {
		$level63 = $this->calendars->chain(self::ID, 'a', 63);
		$this->check($level63, 'new', false);
		$this->addToAssertionCount(1);

		$level64 = $this->calendars->chain(self::ID, 'b', 64);
		$this->assertRefused(fn () => $this->check($level64, 'new', false), TaskHierarchyMessages::tooDeep(64));
		$level65 = $this->calendars->chain(self::ID, 'c', 65);
		$this->assertRefused(fn () => $this->check($level65, 'new', false), TaskHierarchyMessages::tooDeep(64));
	}

	/**
	 * Moving a task with subtasks counts its whole subtree: 40 levels under a parent at level 24 fit, 25 do not.
	 *
	 * @return void
	 */
	public function testAMovedSubtreeKeepsItsDeepestTaskWithinTheCeiling(): void {
		$this->calendars->chain(self::ID, 's', 40);
		$fits = $this->calendars->chain(self::ID, 'p', 24);
		$this->check($fits, 's1', true);
		$this->addToAssertionCount(1);

		$tooDeep = $this->calendars->chain(self::ID, 'q', 25);
		$this->assertRefused(fn () => $this->check($tooDeep, 's1', true), TaskHierarchyMessages::tooDeep(64));
	}

	/**
	 * A task cannot go under itself or under one of its descendants.
	 *
	 * @return void
	 */
	public function testALinkThatClosesALoopIsRefused(): void {
		$deepest = $this->calendars->chain(self::ID, 'l', 5);
		$this->assertRefused(fn () => $this->check($deepest, 'l1', true), TaskHierarchyMessages::loop());
	}

	/**
	 * A loop already present above the requested parent, made by another client, is refused as a broken hierarchy.
	 *
	 * @return void
	 */
	public function testAnExistingLoopAboveTheParentIsRefused(): void {
		$this->calendars->task(self::ID, 'x', 'y')->task(self::ID, 'y', 'z')->task(self::ID, 'z', 'y')->task(self::ID, 't');
		$this->assertRefused(fn () => $this->check('x', 't', true), TaskHierarchyMessages::brokenHierarchy());
	}

	/**
	 * An ancestor that cannot be parsed makes the level unknown and is refused; a missing or trashed one ends the
	 * chain as the Tasks app shows the task at the top.
	 *
	 * @return void
	 */
	public function testBrokenAndMissingAncestors(): void {
		$this->calendars->task(self::ID, 'p', 'bad')->raw(self::ID, 'bad', 'VTODO', 'not a calendar')->task(self::ID, 't');
		$this->assertRefused(fn () => $this->check('p', 't', true), TaskHierarchyMessages::brokenHierarchy());

		$this->calendars->task(self::ID, 'orphan', 'gone')->task(self::ID, 'trashedChild', 'trashed')->task(self::ID, 'trashed', null, '', true);
		$this->check('orphan', 't', true);
		$this->check('trashedChild', 't', true);
		$this->addToAssertionCount(2);
	}

	/**
	 * The parent must be a live, readable VTODO of the same calendar.
	 *
	 * @return void
	 */
	public function testTheParentMustBeALiveReadableTaskOfTheSameCalendar(): void {
		$this->calendars->task(2, 'elsewhere')
			->raw(self::ID, 'event', 'VEVENT', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event\r\nDTSTART:20261001T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")
			->task(self::ID, 'trashed', null, '', true)
			->task(self::ID, 't');
		foreach (['elsewhere', 'event', 'trashed', 'missing'] as $parent) {
			$this->assertRefused(fn () => $this->check($parent, 't', true), TaskHierarchyMessages::parentNotFound());
		}
	}

	/**
	 * In a shared calendar a private or confidential parent reads as missing, while a private ancestor above a
	 * readable parent is counted without being shown: the refusals name no task.
	 *
	 * @return void
	 */
	public function testProtectedTasksOfASharedCalendarAreNeverNamed(): void {
		$this->calendars->task(self::ID, 'secret', null, "CLASS:PRIVATE\r\n")
			->task(self::ID, 'confidential', null, "CLASS:CONFIDENTIAL\r\n")
			->task(self::ID, 'open', 'secret')
			->task(self::ID, 't');
		$shared = $this->calendar('bob');
		foreach (['secret', 'confidential'] as $parent) {
			$this->assertRefused(fn () => $this->hierarchy()->assertParent($shared, 'alice', $parent, 't', true), TaskHierarchyMessages::parentNotFound());
		}
		$this->hierarchy()->assertParent($shared, 'alice', 'open', 't', true);
		$this->calendars->task(self::ID, 'secret', 'open', "CLASS:PRIVATE\r\n");
		try {
			$this->hierarchy()->assertParent($shared, 'alice', 'open', 't', true);
			$this->fail('the loop through a private ancestor was accepted');
		} catch (ToolFailure $e) {
			$this->assertSame(TaskHierarchyMessages::brokenHierarchy(), $e->getMessage());
			$this->assertStringNotContainsString('secret', $e->getMessage());
		}
	}

	/**
	 * A chain read back after a write tells whether a loop runs through the task, within a bounded walk.
	 *
	 * @return void
	 */
	public function testALoopThroughTheTaskIsDetectedAfterAWrite(): void {
		$this->calendars->task(self::ID, 'a', 'b')->task(self::ID, 'b', 'a')->task(self::ID, 'c', 'a');
		$this->assertTrue($this->hierarchy()->loopsThrough(self::ID, 'a'));
		$this->assertFalse($this->hierarchy()->loopsThrough(self::ID, 'c'), 'c reaches a loop it is not part of');
		$this->calendars->chain(self::ID, 'd', 80);
		$this->assertFalse($this->hierarchy()->loopsThrough(self::ID, 'd80'));
	}

	/**
	 * Checks a parent for a task in the calendar of the user.
	 *
	 * @param string $parent UID of the requested parent
	 * @param string $uid UID of the task
	 * @param bool $existing whether the task exists
	 * @return void
	 */
	private function check(string $parent, string $uid, bool $existing): void {
		$this->hierarchy()->assertParent($this->calendar('alice'), 'alice', $parent, $uid, $existing);
	}

	/**
	 * Asserts a refusal with the given message.
	 *
	 * @param callable $check the check
	 * @param string $message expected message
	 * @return void
	 */
	private function assertRefused(callable $check, string $message): void {
		try {
			$check();
			$this->fail('accepted: ' . $message);
		} catch (ToolFailure $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}

	/**
	 * The calendar of the tests, owned by the given user.
	 *
	 * @param string $owner UID of the owner
	 * @return Calendar the calendar
	 */
	private function calendar(string $owner): Calendar {
		return new Calendar(self::ID, 'tasks', 'Tasks', $owner, 'principals/users/' . $owner, true, '/remote.php/dav/calendars/alice/tasks/');
	}

	/**
	 * The hierarchy over the calendars of the test.
	 *
	 * @return TaskHierarchy the hierarchy
	 */
	private function hierarchy(): TaskHierarchy {
		return new TaskHierarchy($this->calendars, $this->calendars, new TaskData());
	}
}
