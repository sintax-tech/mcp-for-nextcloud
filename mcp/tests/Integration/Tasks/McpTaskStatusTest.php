<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tasks;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/** Status round trips through the endpoint and official CalDAV pipeline on the real server. */
final class McpTaskStatusTest extends IntegrationTestCase {
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

	/** @return void Every status creates a real task; cancellation, reopening and contradictions preserve the contract. */
	public function testStatusRoundTripsThroughTheEndpointAndCalDav(): void {
		$uri = 'mcp-it-status-' . $this->run;
		$id = $this->calendar(Fixtures::ALICE, $uri);
		$path = self::HOME . $uri . '/';
		$mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));
		foreach (['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'] as $status) {
			$created = $mcp->write('tasks_create_task', ['calendar' => $path, 'summary' => 'Status ' . $status, 'status' => $status]);
			self::assertTrue($created->ok(), $created->describe());
			$task = $created->json();
			self::assertSame($status, $task['status']);
			self::assertSame($status === 'COMPLETED', $task['completed'] !== null);
			if ($status !== 'COMPLETED') { continue; }
			$args = ['calendar' => $path, 'uid' => $task['uid']];
			$before = $this->caldavGet($path, $task['uid']);
			$bad = $mcp->write('tasks_edit_task', $args + ['status' => 'IN-PROCESS', 'percentComplete' => 100]);
			self::assertFalse($bad->ok(), $bad->describe());
			self::assertSame($before['body'], $this->caldavGet($path, $task['uid'])['body']);
			$cancelled = $mcp->write('tasks_edit_task', $args + ['status' => 'CANCELLED']);
			self::assertTrue($cancelled->ok(), $cancelled->describe());
			self::assertSame(100, $cancelled->json()['percentComplete']);
			self::assertSame($task['completed'], $cancelled->json()['completed']);
			$listed = $mcp->tool('tasks_list_tasks', ['calendar' => $path])->json();
			self::assertContains($task['uid'], array_column($listed['tasks'], 'uid'));
			$plan = $mcp->tool('tasks_edit_task', $args + ['status' => 'IN-PROCESS']);
			self::assertStringContainsString('Completed at', $plan->text());
			$reopened = $mcp->write('tasks_edit_task', $args + ['status' => 'IN-PROCESS']);
			self::assertTrue($reopened->ok(), $reopened->describe());
			self::assertSame(99, $reopened->json()['percentComplete']);
			self::assertNull($reopened->json()['completed']);
			$raw = $this->caldavGet($path, $task['uid'])['body'];
			self::assertStringContainsString('STATUS:IN-PROCESS', $raw);
			self::assertStringContainsString('PERCENT-COMPLETE:99', $raw);
			self::assertStringNotContainsString('COMPLETED:', $raw);
		}
	}

	/** @param string $path calendar path @param string $uid task UID @return array{status:int, headers:string, body:string} fresh HTTP view */
	private function caldavGet(string $path, string $uid): array {
		$result = Http::request('GET', Http::BASE . $path . $uid . '.ics', ['Authorization: ' . LoginFactory::basic(Fixtures::ALICE)->authorization()]);
		self::assertSame(200, $result['status']);
		return $result;
	}

	/** @param string $owner owner UID @param string $uri calendar URI @return int real calendar ID */
	private function calendar(string $owner, string $uri): int {
		$id = (int)Server::get(CalDavBackend::class)->createCalendar('principals/users/' . $owner, $uri, [
			'{DAV:}displayname' => $uri,
			'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VTODO']),
		]);
		$this->created[] = $id;
		return $id;
	}

}
