<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Routines;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/**
 * routines_create_meeting over the real Talk and Calendar of the cell, through the endpoint.
 *
 * The plan creates nothing; the confirmed call creates one private room with Bob, posts the agenda in it and only then
 * the event in Alice's personal calendar, whose LOCATION is the link the instance gives the room. With calendar.create
 * revoked after the plan, the preflight refuses the meeting before the room exists. The rows this test created are
 * removed in tearDown; the tool itself never deletes anything.
 */
final class MeetingTest extends IntegrationTestCase {
	/** The grants the meeting needs: its own and one per step. */
	private const GRANTS = [['routines', 'meeting'], ['talk', 'create'], ['talk', 'reply'], ['calendar', 'create']];

	/** @var array<string, bool> grants of Alice before the test, by module.operation */
	private array $before = [];
	/** @var list<string> rooms this test created */
	private array $rooms = [];
	/** @var list<string> event UIDs this test created */
	private array $events = [];
	/** @var array<string, string> address of each fixture account before the test, '' for none */
	private array $emails = [];

	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppManager::class);
		if (!AppEnablement::forAnyone($apps, 'spreed') || !AppEnablement::forAnyone($apps, 'calendar')) {
			$this->markTestSkipped('Talk or Calendar is not enabled in this cell; see provision.log in its artifact');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(Fixtures::ALICE, true);
		$policy->setConnected(Fixtures::ALICE, true);
		foreach (self::GRANTS as [$module, $operation]) {
			$this->before[$module . '.' . $operation] = $policy->granted(Fixtures::ALICE, $module, $operation);
			$policy->setGrant(Fixtures::ALICE, $module, $operation, true);
		}
		// An event with guests needs an address for its organizer and for each guest, as calendar_create_event does.
		foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
			$user = $this->fixture($uid);
			$this->emails[$uid] = (string)$user->getSystemEMailAddress();
			$user->setSystemEMailAddress($uid . '@example.invalid');
		}
	}

	protected function tearDown(): void {
		foreach ($this->emails as $uid => $email) {
			$this->fixture($uid)->setSystemEMailAddress($email);
		}
		$policy = Server::get(GrantPolicy::class);
		foreach ($this->before as $grant => $enabled) {
			[$module, $operation] = explode('.', $grant, 2);
			$policy->setGrant(Fixtures::ALICE, $module, $operation, $enabled);
		}
		foreach ($this->rooms as $token) {
			$manager = Server::get(\OCA\Talk\Manager::class);
			Server::get(\OCA\Talk\Service\RoomService::class)->deleteRoom($manager->getRoomByToken($token));
		}
		$backend = Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
		foreach ($this->events as $uid) {
			$calendar = $backend->getCalendarByUri('principals/users/' . Fixtures::ALICE, 'personal');
			if ($calendar !== null) {
				$backend->deleteCalendarObject((int)$calendar['id'], $uid . '.ics', \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
			}
		}
		parent::tearDown();
	}

	public function testThePlanCreatesNothingAndTheConfirmedCallCreatesRoomAgendaAndEvent(): void {
		$alice = new McpClient(LoginFactory::basic(Fixtures::ALICE));
		$arguments = $this->arguments('full');
		$rooms = $this->roomCount($arguments['title']);

		$plan = $alice->tool('routines_create_meeting', $arguments);
		$this->assertTrue($plan->ok(), $plan->describe());
		$this->assertSame($rooms, $this->roomCount($arguments['title']), 'the plan created a room');

		$reply = $alice->tool('routines_create_meeting', $arguments + ['confirm' => true]);
		$this->assertTrue($reply->ok(), $reply->describe());
		$receipt = $reply->json();
		$this->assertSame('completed', $receipt['status'] ?? null, $reply->describe());
		[$room, $agenda, $event] = $receipt['steps'];
		$token = (string)$room['ids']['conversation_token'];
		$this->rooms[] = $token;
		$this->events[] = (string)$event['ids']['uid'];
		$this->assertStringEndsWith('/call/' . $token, (string)$room['url']);
		$this->assertSame($rooms + 1, $this->roomCount($arguments['title']));
		$this->assertSame('applied', $agenda['status']);
		$this->assertSame($room['url'], $event['result']['location'] ?? null, 'the event points to the room');
	}

	public function testAStepRevokedAfterThePlanRefusesTheMeetingBeforeTheRoomExists(): void {
		$alice = new McpClient(LoginFactory::basic(Fixtures::ALICE));
		$arguments = $this->arguments('refused');
		$rooms = $this->roomCount($arguments['title']);
		$this->assertTrue($alice->tool('routines_create_meeting', $arguments)->ok());

		Server::get(GrantPolicy::class)->setGrant(Fixtures::ALICE, 'calendar', 'create', false);
		$reply = $alice->tool('routines_create_meeting', $arguments + ['confirm' => true]);

		$receipt = $reply->json();
		$this->assertSame('refused', $receipt['status'] ?? null, $reply->describe());
		$this->assertSame(['room', 'agenda', 'event'], $receipt['not_done']);
		$this->assertSame($rooms, $this->roomCount($arguments['title']), 'a refused meeting created a room');
	}

	/**
	 * @param string $label distinguishes the rooms of each test
	 * @return array<string, mixed> arguments of a meeting tomorrow with Bob
	 */
	private function arguments(string $label): array {
		$day = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d');
		return ['title' => 'mcp-c1-' . $label . '-' . bin2hex(random_bytes(4)), 'participants' => [Fixtures::BOB],
			'agenda' => "1. Opening\n2. Deadlines", 'calendar' => '/remote.php/dav/calendars/' . Fixtures::ALICE . '/personal/',
			'start' => $day . 'T14:00:00Z', 'end' => $day . 'T15:00:00Z'];
	}

	/**
	 * @param string $uid a fixture account
	 * @return IUser the account, which the harness creates before the suite
	 */
	private function fixture(string $uid): IUser {
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertInstanceOf(IUser::class, $user);
		return $user;
	}

	/**
	 * @param string $name room name
	 * @return int rooms with that name
	 */
	private function roomCount(string $name): int {
		$query = $this->official()->getQueryBuilder();
		$query->select($query->func()->count('*'))->from('talk_rooms')->where($query->expr()->eq('name', $query->createNamedParameter($name)));
		$result = $query->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
