<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tasks;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\ScopedCalendarAccess;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCA\Mcp\Tools\Common\OutputReferenceFilter;
use OCA\Mcp\Tools\Tasks\TaskData;
use OCA\Mcp\Tools\Tasks\TaskHierarchyMessages;
use OCA\Mcp\Tools\Tasks\TasksModule;
use OCA\Mcp\Tools\Tasks\TaskStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use RuntimeException;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Throwable;

/**
 * Subtasks on the real server: the endpoint with an app password and with an OAuth access token, the CalDAV
 * objects other clients read and write, the If-Match of the write, grants, a protected parent in a shared calendar,
 * the interleaving of reparenting writes (A under B while B goes under A) under the hierarchy lock, and the refusal
 * when the server's locking excludes nothing.
 */
final class McpSubtasksTest extends IntegrationTestCase {
	/** CalDAV home of alice. */
	private const HOME = '/remote.php/dav/calendars/' . Fixtures::ALICE . '/';

	/** Random suffix of this run's objects. */
	private string $run = '';

	/** @var list<int> calendars to delete */
	private array $created = [];

	/** @var array<string, string|null> raw policy values before the test; null = absent */
	private array $before = [];

	/**
	 * Opens the service to alice and grants her the task writes, remembering what to put back.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->run = bin2hex(random_bytes(4));
		$apps = Server::get(IAppConfig::class);
		$users = Server::get(IUserConfig::class);
		$this->before = [
			'service' => $apps->hasKey('mcp', GrantPolicy::SERVICE_KEY) ? $apps->getValueString('mcp', GrantPolicy::SERVICE_KEY) : null,
			'eligible' => $users->hasKey(Fixtures::ALICE, 'mcp', GrantPolicy::ELIGIBLE_KEY) ? $users->getValueString(Fixtures::ALICE, 'mcp', GrantPolicy::ELIGIBLE_KEY) : null,
			'connected' => $users->hasKey(Fixtures::ALICE, 'mcp', GrantPolicy::CONNECTED_KEY) ? $users->getValueString(Fixtures::ALICE, 'mcp', GrantPolicy::CONNECTED_KEY) : null,
		];
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(Fixtures::ALICE, true);
		$policy->setConnected(Fixtures::ALICE, true);
		foreach (['create', 'edit'] as $operation) {
			$policy->setGrant(Fixtures::ALICE, 'tasks', $operation, true);
		}
	}

	/**
	 * Puts the policy back and deletes the calendars of the run.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$policy = Server::get(GrantPolicy::class);
		foreach (['create', 'edit'] as $operation) {
			$policy->setGrant(Fixtures::ALICE, 'tasks', $operation, false);
		}
		$config = Server::get(IConfig::class);
		$this->before['service'] === null ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->before['service']);
		foreach (['eligible' => GrantPolicy::ELIGIBLE_KEY, 'connected' => GrantPolicy::CONNECTED_KEY] as $name => $key) {
			$this->before[$name] === null ? $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key) : $config->setUserValue(Fixtures::ALICE, 'mcp', $key, $this->before[$name]);
		}
		Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
		Server::get(IAppConfig::class)->clearCache(false);
		foreach ($this->created as $id) {
			try {
				Server::get(CalDavBackend::class)->deleteCalendar($id, true);
			} catch (Throwable) {
				// Already gone.
			}
		}
		parent::tearDown();
	}

	/**
	 * With an app password and with an OAuth token: create a parent and a subtask, read and list them, see the
	 * parent named in the plan, make the subtask top-level again; without the edit grant the write is refused.
	 *
	 * @return void
	 */
	public function testSubtasksOverTheEndpointWithBothCredentials(): void {
		$uri = "mcp-it-sub-{$this->run}";
		$this->calendar(Fixtures::ALICE, $uri);
		$path = self::HOME . $uri . '/';
		foreach (['basic' => LoginFactory::basic(Fixtures::ALICE), 'bearer' => LoginFactory::bearer(Fixtures::ALICE)] as $kind => $credential) {
			$list = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
				'Authorization: ' . $credential->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
			], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
			$tools = array_column(json_decode($list['body'], true, 512, JSON_THROW_ON_ERROR)['result']['tools'], null, 'name');
			$this->assertSame(['string', 'null'], $tools['tasks_create_task']['inputSchema']['properties']['parent']['type'], $kind);
			$this->assertArrayHasKey('parent', $tools['tasks_list_tasks']['inputSchema']['properties'], $kind);

			$mcp = new McpClient($credential);
			$parent = $mcp->write('tasks_create_task', ['calendar' => $path, 'summary' => "Prepare purchase $kind"]);
			$this->assertTrue($parent->ok(), $parent->describe());
			$parentUid = $parent->json()['uid'];
			$plan = $mcp->tool('tasks_create_task', ['calendar' => $path, 'summary' => 'Review contract', 'parent' => $parentUid]);
			$this->assertStringContainsString("Prepare purchase $kind", $plan->text(), $kind);
			$this->assertStringContainsString($parentUid, $plan->text(), $kind);
			$child = $mcp->write('tasks_create_task', ['calendar' => $path, 'summary' => 'Review contract', 'parent' => $parentUid]);
			$this->assertTrue($child->ok(), $child->describe());
			$childUid = $child->json()['uid'];
			$this->assertSame($parentUid, $mcp->tool('tasks_read_task', ['calendar' => $path, 'uid' => $childUid])->json()['parent']);
			$listed = $mcp->tool('tasks_list_tasks', ['calendar' => $path, 'parent' => $parentUid])->json();
			$this->assertSame([$childUid], array_column($listed['tasks'], 'uid'), $kind);

			$top = $mcp->write('tasks_edit_task', ['calendar' => $path, 'uid' => $childUid, 'parent' => null]);
			$this->assertTrue($top->ok(), $top->describe());
			$this->assertNull($top->json()['parent']);
			$missing = $mcp->tool('tasks_create_task', ['calendar' => $path, 'summary' => 'Orphan', 'parent' => "nope-{$this->run}"]);
			$this->assertTrue($missing->isError(), $missing->describe());
			$this->assertSame(TaskHierarchyMessages::parentNotFound(), $missing->text());
		}

		Server::get(GrantPolicy::class)->setGrant(Fixtures::ALICE, 'tasks', 'edit', false);
		$refused = (new McpClient(LoginFactory::basic(Fixtures::ALICE)))->write('tasks_edit_task', ['calendar' => $path, 'uid' => $childUid, 'parent' => $parentUid]);
		$this->assertFalse($refused->ok(), 'the edit grant is required');
		$this->assertNull($this->stored($path, $childUid)['parent'], 'nothing was written without the grant');
	}

	/**
	 * A task written by another CalDAV client keeps its other relations and properties when the MCP sets its parent;
	 * the object read back over CalDAV carries the link the Tasks app reads; a change made over CalDAV after the
	 * plan makes the confirmed write fail on its ETag.
	 *
	 * @return void
	 */
	public function testRoundTripWithCalDavAndIfMatch(): void {
		$uri = "mcp-it-rt-{$this->run}";
		$this->calendar(Fixtures::ALICE, $uri);
		$path = self::HOME . $uri . '/';
		$this->caldavPut($path, 'p', "UID:p-{$this->run}\r\nSUMMARY:Parent\r\n");
		$this->caldavPut($path, 't', "UID:t-{$this->run}\r\nSUMMARY:Task\r\nRELATED-TO;RELTYPE=SIBLING:s-{$this->run}\r\nX-CLIENT-PROP;X-P=1:kept\r\n");
		$mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));

		$edit = $mcp->write('tasks_edit_task', ['calendar' => $path, 'uid' => "t-{$this->run}", 'parent' => "p-{$this->run}"]);
		$this->assertTrue($edit->ok(), $edit->describe());
		$ics = $this->caldavGet($path, 't')['body'];
		$this->assertStringContainsString("RELATED-TO;RELTYPE=PARENT:p-{$this->run}", $ics);
		$this->assertStringContainsString("RELATED-TO;RELTYPE=SIBLING:s-{$this->run}", $ics);
		$this->assertStringContainsString('X-CLIENT-PROP;X-P=1:kept', $ics);

		$plan = $mcp->tool('tasks_edit_task', ['calendar' => $path, 'uid' => "t-{$this->run}", 'parent' => null]);
		$etag = $plan->structured()['etag'] ?? null;
		$this->assertIsString($etag, $plan->describe());
		$this->caldavPut($path, 't', "UID:t-{$this->run}\r\nSUMMARY:Changed elsewhere\r\nRELATED-TO;RELTYPE=PARENT:p-{$this->run}\r\n");
		$stale = $mcp->write('tasks_edit_task', ['calendar' => $path, 'uid' => "t-{$this->run}", 'parent' => null, 'etag' => $etag]);
		$this->assertTrue($stale->isError(), $stale->describe());
		$this->assertSame("p-{$this->run}", $this->stored($path, "t-{$this->run}")['parent'], 'the stale confirmation wrote nothing');
	}

	/**
	 * In a calendar bob shares with alice, a private task of bob cannot be used as a parent, and the refusal is the
	 * one of a task that does not exist.
	 *
	 * @return void
	 */
	public function testAProtectedParentOfASharedCalendarReadsAsMissing(): void {
		$uri = "mcp-it-shared-{$this->run}";
		$id = $this->calendar(Fixtures::BOB, $uri);
		$backend = Server::get(CalDavBackend::class);
		$backend->createCalendarObject($id, 'secret.ics', $this->ics("UID:secret-{$this->run}\r\nSUMMARY:Secret\r\nCLASS:PRIVATE\r\n"));
		$backend->createCalendarObject($id, 'open.ics', $this->ics("UID:open-{$this->run}\r\nSUMMARY:Open\r\n"));
		$this->share($uri);
		$path = self::HOME . $uri . '_shared_by_' . Fixtures::BOB . '/';
		$mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));
		$secret = $mcp->tool('tasks_edit_task', ['calendar' => $path, 'uid' => "open-{$this->run}", 'parent' => "secret-{$this->run}"]);
		$absent = $mcp->tool('tasks_edit_task', ['calendar' => $path, 'uid' => "open-{$this->run}", 'parent' => "absent-{$this->run}"]);
		$this->assertTrue($secret->isError(), $secret->describe());
		$this->assertSame($absent->fingerprint(), $secret->fingerprint());
		$this->assertStringNotContainsString('Secret', $secret->text());
	}

	/**
	 * Reparenting writes slipped between the check and the write of A→B, with the production hierarchy lock and the
	 * cell's real locking provider: a second MCP write B→A is refused as busy and no loop exists; a change made by
	 * another client (the CalDAV backend, which takes no MCP lock) still closes the loop, and the answer of A→B
	 * reports it.
	 *
	 * Limit of this proof: both MCP calls run in the same PHP process, for the same user, sharing one provider
	 * instance. It shows the critical section and the refusal, not two HTTP workers or two users of a shared calendar
	 * at once; across processes the exclusion rests on the provider's shared storage (database row or memcache).
	 *
	 * @return void
	 */
	public function testInterleavedReparentingUnderTheHierarchyLock(): void {
		$mcp = $this->interleave(false);
		$this->assertSame(TaskHierarchyMessages::busy(), $mcp['second']);
		$this->assertSame($mcp['b'], $mcp['first']['parent']);
		$this->assertNull($mcp['bParent'], 'the refused write changed nothing');
		$this->assertArrayNotHasKey('warning', $mcp['first']);

		$external = $this->interleave(true);
		$this->assertSame('ok', $external['second']);
		$this->assertSame($external['b'], $external['first']['parent']);
		$this->assertSame($external['a'], $external['bParent'], 'the other client closed the loop the lock does not cover');
		$this->assertSame(TaskHierarchyMessages::loopAfterWrite(), $external['first']['warning']);
	}

	/**
	 * The locking provider the server returns with `filelocking.enabled` false (the core's NoopLockingProvider, used
	 * here directly, as that factory would) makes a confirmed write that sets a parent refuse before DAV, while the
	 * cell's own provider excludes for real; making the task top-level still writes.
	 *
	 * @return void
	 */
	public function testWithoutFileLockingTheParentWriteIsRefusedBeforeDav(): void {
		$cell = Server::get(ILockingProvider::class);
		$probe = 'mcp/it/probe/' . $this->run;
		$cell->acquireLock($probe, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->assertTrue($cell->isLocked($probe, ILockingProvider::LOCK_EXCLUSIVE), 'the cell runs with effective file locking');
		} finally {
			$cell->releaseLock($probe, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$uri = "mcp-it-nolock-{$this->run}";
		$id = $this->calendar(Fixtures::ALICE, $uri);
		$path = self::HOME . $uri . '/';
		$backend = Server::get(CalDavBackend::class);
		$backend->createCalendarObject($id, 'a.ics', $this->ics("UID:a-{$this->run}\r\nSUMMARY:A\r\n"));
		$backend->createCalendarObject($id, 'b.ics', $this->ics("UID:b-{$this->run}\r\nSUMMARY:B\r\n"));
		$before = $backend->getCalendarObject($id, 'a.ics')['etag'];
		$writes = 0;
		$dav = new InterleavingDav(Server::get(CalendarDav::class), function () use (&$writes): void {
			$writes++;
		});
		$module = $this->module($dav, false, new \OC\Lock\NoopLockingProvider());
		$session = Server::get(IUserSession::class);
		$session->setUser(Server::get(IUserManager::class)->get(Fixtures::ALICE));
		try {
			$refused = Server::get(GrantPolicy::class)->within(Fixtures::ALICE, function () use ($module, $path): string {
				try {
					$module->call('tasks_edit_task', ['calendar' => $path, 'uid' => "a-{$this->run}", 'parent' => "b-{$this->run}", 'confirm' => true], Fixtures::ALICE);
					return 'written';
				} catch (Throwable $e) {
					return $e->getMessage();
				}
			});
			$this->assertSame(TaskHierarchyMessages::lockingUnavailable(), $refused);
			$this->assertSame(0, $writes, 'no DAV write was attempted');
			$this->assertSame($before, $backend->getCalendarObject($id, 'a.ics')['etag']);
			$this->assertNull($this->stored($path, "a-{$this->run}")['parent']);

			$top = Server::get(GrantPolicy::class)->within(Fixtures::ALICE, fn (): array => $module->call(
				'tasks_edit_task', ['calendar' => $path, 'uid' => "a-{$this->run}", 'parent' => null, 'confirm' => true], Fixtures::ALICE));
			$this->assertNull(json_decode($top['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['parent']);
			$this->assertSame(1, $writes, 'making the task top-level still writes');
		} finally {
			$session->setUser(null);
		}
	}

	/**
	 * Runs A→B, under the production lock, with B→A slipped in just before the write of A→B.
	 *
	 * @param bool $external whether B→A comes from another client (the CalDAV backend) instead of a second MCP call
	 * @return array{a:string, b:string, first:array<string, mixed>, second:string, bParent:?string} what happened
	 */
	private function interleave(bool $external): array {
		$uri = "mcp-it-race-{$this->run}-" . ($external ? 'external' : 'mcp');
		$id = $this->calendar(Fixtures::ALICE, $uri);
		$path = self::HOME . $uri . '/';
		$a = "a-{$this->run}";
		$b = "b-{$this->run}";
		$backend = Server::get(CalDavBackend::class);
		$backend->createCalendarObject($id, 'a.ics', $this->ics("UID:$a\r\nSUMMARY:A\r\n"));
		$backend->createCalendarObject($id, 'b.ics', $this->ics("UID:$b\r\nSUMMARY:B\r\n"));
		$second = 'not run';
		$inner = Server::get(CalendarDav::class);
		$late = $this->module($inner, true);
		$dav = new InterleavingDav($inner, function () use ($external, $late, $backend, $id, $path, $a, $b, &$second): void {
			try {
				if ($external) {
					$backend->updateCalendarObject($id, 'b.ics', $this->ics("UID:$b\r\nSUMMARY:B\r\nRELATED-TO:$a\r\n"));
				} else {
					$late->call('tasks_edit_task', ['calendar' => $path, 'uid' => $b, 'parent' => $a, 'confirm' => true], Fixtures::ALICE);
				}
				$second = 'ok';
			} catch (Throwable $e) {
				$second = $e->getMessage();
			}
		});
		// The embedded DAV server acts only for the user of the session, as in an endpoint request.
		$session = Server::get(IUserSession::class);
		$session->setUser(Server::get(IUserManager::class)->get(Fixtures::ALICE));
		try {
			$first = Server::get(GrantPolicy::class)->within(Fixtures::ALICE, fn (): array => $this->module($dav, true)->call(
				'tasks_edit_task', ['calendar' => $path, 'uid' => $a, 'parent' => $b, 'confirm' => true], Fixtures::ALICE));
		} finally {
			$session->setUser(null);
		}
		return ['a' => $a, 'b' => $b, 'first' => json_decode($first['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR),
			'second' => $second, 'bParent' => $this->stored($path, $b)['parent']];
	}

	/**
	 * The production module with a given DAV port.
	 *
	 * @param CalendarDav $dav DAV write port
	 * @param bool $withLock whether it takes the server's locking provider
	 * @param ILockingProvider|null $locks another provider, taken instead when given
	 * @return TasksModule the module
	 */
	private function module(CalendarDav $dav, bool $withLock, ?ILockingProvider $locks = null): TasksModule {
		return new TasksModule(
			Server::get(ScopedCalendarAccess::class),
			Server::get(CalendarStore::class),
			Server::get(TaskStore::class),
			$dav,
			Server::get(TaskData::class),
			Server::get(SharedGuard::class),
			Server::get(TrashPolicy::class),
			Server::get(ITimeFactory::class),
			Server::get(OutputReferenceFilter::class),
			Server::get(UserTimezone::class),
			$locks ?? ($withLock ? Server::get(ILockingProvider::class) : null),
		);
	}

	/**
	 * A task as tasks_read_task answers alice now, through the production module.
	 *
	 * @param string $path calendar path
	 * @param string $uid task UID
	 * @return array<string, mixed> the task
	 */
	private function stored(string $path, string $uid): array {
		$result = Server::get(GrantPolicy::class)->within(Fixtures::ALICE, fn (): array => Server::get(TasksModule::class)
			->call('tasks_read_task', ['calendar' => $path, 'uid' => $uid], Fixtures::ALICE));
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * A VTODO calendar of the run.
	 *
	 * @param string $owner UID of the owner
	 * @param string $uri calendar URI
	 * @return int calendar ID
	 */
	private function calendar(string $owner, string $uri): int {
		$id = (int)Server::get(CalDavBackend::class)->createCalendar('principals/users/' . $owner, $uri, [
			'{DAV:}displayname' => $uri,
			'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO']),
		]);
		$this->created[] = $id;
		return $id;
	}

	/**
	 * A minimal VTODO document.
	 *
	 * @param string $lines property lines of the VTODO
	 * @return string the document
	 */
	private function ics(string $lines): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//mcp-it//EN\r\nBEGIN:VTODO\r\nDTSTAMP:20261001T100000Z\r\n$lines" . "END:VTODO\r\nEND:VCALENDAR\r\n";
	}

	/**
	 * Writes an object as another CalDAV client of alice does.
	 *
	 * @param string $path calendar path
	 * @param string $name object name without extension
	 * @param string $lines property lines of the VTODO
	 * @return void
	 */
	private function caldavPut(string $path, string $name, string $lines): void {
		$response = Http::request('PUT', Http::BASE . $path . $name . '.ics', [
			'Authorization: ' . LoginFactory::basic(Fixtures::ALICE)->authorization(), 'Content-Type: text/calendar; charset=utf-8',
		], $this->ics($lines));
		if (!in_array($response['status'], [201, 204], true)) {
			throw new RuntimeException("CalDAV PUT $name answered HTTP {$response['status']}: " . substr($response['body'], 0, 400));
		}
	}

	/**
	 * Reads an object as another CalDAV client of alice does.
	 *
	 * @param string $path calendar path
	 * @param string $name object name without extension
	 * @return array{status:int, headers:string, body:string} the response
	 */
	private function caldavGet(string $path, string $name): array {
		return Http::request('GET', Http::BASE . $path . $name . '.ics', ['Authorization: ' . LoginFactory::basic(Fixtures::ALICE)->authorization()]);
	}

	/**
	 * bob shares a calendar with alice, read-write, through CalDAV as the Calendar app does.
	 *
	 * @param string $uri calendar URI of bob
	 * @return void
	 */
	private function share(string $uri): void {
		$href = '<d:href>principal:principals/users/' . Fixtures::ALICE . '</d:href>';
		$body = '<?xml version="1.0" encoding="utf-8"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns">'
			. "<o:set>$href<o:read-write/></o:set></o:share>";
		$response = Http::request('POST', Http::BASE . '/remote.php/dav/calendars/' . Fixtures::BOB . '/' . $uri . '/', [
			'Authorization: ' . LoginFactory::basic(Fixtures::BOB)->authorization(), 'Content-Type: application/xml; charset=utf-8',
		], $body);
		if (!in_array($response['status'], [200, 204], true)) {
			throw new RuntimeException("Share of $uri answered HTTP {$response['status']}: " . substr($response['body'], 0, 400));
		}
	}
}
