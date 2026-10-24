<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks\Handler;

use DateTimeImmutable;
use OCA\Mcp\Tests\Unit\InMemoryLocks;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\DavResult;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Calendar and module shared by the category tests and the list-tasks handler tests.
 *
 * One visible task calendar, a store that serves the objects planted in {@see $tasks}, and the module wired
 * to both. A test replaces {@see $tasks} or {@see $owner} before it calls the tool.
 */
abstract class TaskListingCase extends TestCase {
	/** Path of the task calendar the tests address. */
	protected const PATH = '/remote.php/dav/calendars/alice/tasks/';

	/** @var array<string, string> serialized task by UID, in calendar order */
	protected array $tasks = [];

	/** Owner principal of the calendar. A share test points this at another user. */
	protected string $owner = 'principals/users/alice';

	/** DAV client the write tests expect to be called. */
	protected CalendarDav $dav;

	/** Module under test, rebuilt for each test. */
	protected TasksModule $module;

	/** Locks the module takes; a test that exercises the hierarchy lock reads what was acquired. */
	protected InMemoryLocks $locks;

	/** @var \Closure(?ILockingProvider): TasksModule builds the module of this calendar with a locking provider */
	private \Closure $wire;

	/**
	 * Plants one open task and wires the module to a calendar owned by {@see $owner}.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = ['t1' => $this->task('t1', 'Work')];
		$store = $this->createMock(CalendarStore::class);
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
		$this->wire = fn (?ILockingProvider $locks): TasksModule => new TasksModule(
			ScopeStub::access($store),
			$store,
			$uris,
			$this->dav,
			new TaskData(),
			new SharedGuard($this->createMock(IUserManager::class)),
			new TrashPolicy($config),
			$time,
			\OCA\Mcp\Tests\Unit\Tools\Common\OutputReferenceFilters::inactive($this),
			null,
			$locks
		);
		$this->module = $this->withLocks($this->locks = new InMemoryLocks());
	}

	/**
	 * The module of this calendar with another locking provider, or none.
	 *
	 * @param ILockingProvider|null $locks provider the module takes
	 * @return TasksModule the module, sharing the store, the DAV double and the planted tasks
	 */
	protected function withLocks(?ILockingProvider $locks): TasksModule {
		return ($this->wire)($locks);
	}

	/**
	 * Serializes a simple task, with extra property lines placed before its summary.
	 *
	 * @param string $uid UID written into the object
	 * @param string $summary summary of the task
	 * @param string $extra property lines placed before the summary
	 * @return string iCalendar document
	 */
	protected function task(string $uid, string $summary, string $extra = ''): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\n"
			. "UID:$uid\r\nDTSTAMP:20261001T100000Z\r\n" . $extra
			. "SUMMARY:$summary\r\nSTATUS:NEEDS-ACTION\r\nX-CUSTOM:keep\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	/**
	 * Reads one task through the tool.
	 *
	 * @param string $uid UID of the task
	 * @return array<string, mixed> decoded task
	 */
	protected function read(string $uid): array {
		return $this->json($this->module->call('tasks_read_task', ['calendar' => self::PATH, 'uid' => $uid], 'alice'));
	}

	/**
	 * Lists tasks of the test calendar.
	 *
	 * @param array<string, mixed> $arguments listing arguments besides the calendar
	 * @return array<string, mixed> decoded page
	 */
	protected function list(array $arguments): array {
		return $this->json($this->module->call('tasks_list_tasks', ['calendar' => self::PATH] + $arguments, 'alice'));
	}

	/**
	 * Expects one update of t1, checks its body and stores it so the read-back sees it.
	 *
	 * @param callable $check assertion run against the body sent to CalDAV
	 * @return void
	 */
	protected function expectUpdate(callable $check): void {
		$this->dav->expects(self::once())->method('update')->willReturnCallback(
			function ($user, $calendar, $uri, $etag, $data) use ($check) {
				$check($data);
				$this->tasks['t1'] = $data;
				return new DavResult(204);
			}
		);
	}

	/**
	 * Decodes the text of an MCP result.
	 *
	 * @param array<string, mixed> $result MCP result returned by the module
	 * @return array<string, mixed> decoded JSON object
	 * @throws \JsonException when the result text is not valid JSON
	 */
	protected function json(array $result): array {
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
