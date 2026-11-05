<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use DateTimeImmutable;
use OCA\Mcp\Modules\Tasks\Writing\TaskSharedNotice as SharedGuard;
use OCA\Mcp\Modules\Tasks\Writing\TaskTrashPolicy as TrashPolicy;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tests\Unit\Access\Visibility\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\InMemoryLocks;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Modules\Tasks\Composing\TaskData;
use OCA\Mcp\Modules\Tasks\Locating\TaskStore;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Records the public Tasks plans, results, failures and DAV effects before RE-15b changes their construction. */
final class TasksCharacterizationTest extends TestCase {
	private const PATH = '/remote.php/dav/calendars/alice/tasks/';
	private const FIXTURE = __DIR__ . '/../../../fixtures/tasks-characterization.json';

	public function testThePlansAndEffectsRemainIdentical(): void {
		$transcript = [];
		foreach (['own', 'shared', 'readonly', 'scope-empty', 'scope-unavailable', 'private', 'recurring', 'participant', 'trash-off', 'no-lock'] as $scenario) {
			foreach (self::steps() as [$kind, $tool, $arguments]) {
				$store = new FakeCalendarStore();
				$store->addCalendar('principals/users/alice', 7, 'tasks', $scenario === 'shared' || $scenario === 'private' ? 'principals/users/bob' : 'principals/users/alice', ['components' => ['VTODO'], 'readOnly' => $scenario === 'readonly']);
				$extra = match ($scenario) {
					'private' => "CLASS:PRIVATE\r\n",
					'recurring' => "RRULE:FREQ=DAILY\r\n",
					'participant' => "ATTENDEE:mailto:other@example.invalid\r\n",
					default => '',
				};
				$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20261001T100000Z\r\nSUMMARY:Work\r\nSTATUS:NEEDS-ACTION\r\nX-CUSTOM:keep\r\n$extra" . "END:VTODO\r\nEND:VCALENDAR\r\n";
				$store->addObject(7, 't1.ics', $ics);
				$dav = new FakeCalendarDav($store);
				$scope = new ScopeStub();
				if ($scenario === 'scope-empty') { $scope->restrict('tasks', []); }
				$scope->unavailable = $scenario === 'scope-unavailable';
				$tasks = $this->createMock(TaskStore::class);
				$tasks->method('uris')->willReturnCallback(static fn (int $id): array => array_keys($store->objects[$id] ?? []));
				$config = $this->createMock(IConfig::class);
				$config->method('getAppValue')->willReturn($scenario === 'trash-off' ? '0' : '');
				$time = $this->createMock(ITimeFactory::class);
				$time->method('now')->willReturn(new DateTimeImmutable('2026-10-01T12:00:00Z'));
				$module = \OCA\Mcp\Tests\Unit\Tools\Tasks\TasksTestModule::build(ScopeStub::access($store, $scope), $store, $tasks, $dav, new TaskData(),
					new SharedGuard($this->createMock(IUserManager::class)), new TrashPolicy($config), $time,
					OutputReferenceFilters::inactive($this), null, $scenario === 'no-lock' ? null : new InMemoryLocks());
				$arguments = ['calendar' => self::PATH, 'uid' => 't1'] + $arguments;
				try {
					$result = $kind === 'preview' ? $module->preview($tool, $arguments, 'alice') : $module->call($tool, $arguments, 'alice');
					// Date-bearing rendering is covered by the existing renderer suite (intl is absent in the official unit image).
					if ($kind === 'preview' && in_array($tool, ['tasks_create_task', 'tasks_edit_task', 'tasks_delete_task'], true)) {
						$result = ['plan' => $result, 'text' => $module->renderPlan($tool, $result)];
					}
				} catch (Throwable $error) {
					// The approved date-parser extraction preserves the shared validation contract, not its empty Calendar subclass.
					$result = ['failure' => $error instanceof ArgumentValidationException ? ArgumentValidationException::class : get_class($error), 'message' => $error->getMessage(), 'code' => $error->getCode()];
					if ($error instanceof ArgumentValidationException) { $result['details'] = $error->details(); $result['clientMessage'] = $error->clientMessage(); }
				}
				$transcript[$scenario][] = ['kind' => $kind, 'tool' => $tool, 'arguments' => $arguments, 'result' => $result, 'scope' => $scope->asked, 'dav' => $dav->calls, 'stored' => $store->objects];
			}
		}
		// Only random task identities and hash ETags are volatile; status, progress, timestamps, text and effects are preserved.
		$json = json_encode($transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$json = preg_replace('/[a-f0-9]{32}/', '<random-uid-or-etag>', $json) . "\n";
		if (getenv('MCP_TASKS_CHARACTERIZE_WRITE') === '1') { file_put_contents(self::FIXTURE, $json); }
		if ($path = getenv('MCP_TASKS_CHARACTERIZE_OUTPUT')) { file_put_contents($path, $json); }
		self::assertSame((string)file_get_contents(self::FIXTURE), $json);
	}

	private static function steps(): array {
		$steps = [['call', 'tasks_list_calendars', []], ['call', 'tasks_list_tasks', []], ['call', 'tasks_read_task', []],
			['call', 'tasks_list_tasks', ['category' => 'missing']], ['call', 'tasks_list_tasks', ['parent' => 'missing']]];
		foreach (['tasks_create_task', 'tasks_edit_task', 'tasks_complete_task', 'tasks_delete_task'] as $tool) {
			$fields = $tool === 'tasks_create_task' ? ['summary' => 'New task'] : ($tool === 'tasks_edit_task' ? ['description' => 'Changed'] : []);
			foreach ([['preview', []], ['call', []], ['call', ['confirm' => false]], ['call', ['confirm' => true]], ['call', ['confirm' => true, 'confirm_shared' => true]]] as [$kind, $confirm]) {
				$steps[] = [$kind, $tool, $fields + $confirm];
			}
		}
		foreach ([['percentComplete' => 0], ['percentComplete' => 40], ['percentComplete' => 100], ['due' => 'invalid'], ['parent' => 'missing'], ['parent' => 't1'], ['parent' => null], ['categories' => ['alpha', 'Beta']], ['etag' => 'stale', 'description' => 'Changed'], []] as $fields) {
			$steps[] = ['call', 'tasks_edit_task', $fields + ['confirm' => true, 'confirm_shared' => true]];
		}
		return $steps;
	}
}
