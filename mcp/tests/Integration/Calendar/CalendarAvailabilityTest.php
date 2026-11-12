<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;

/** Existing CI cells only: real CalDAV free/busy through the authenticated MCP endpoint, without event disclosure. */
final class CalendarAvailabilityTest extends IntegrationTestCase {
	/** @var list<IUser> disposable accounts owned by this test */
	private array $actors = [];
	/** Original global service value restored after the test. */
	private ?string $service = null;

	/** @return void delete only the test accounts and restore the shared switch, even on failure */
	protected function tearDown(): void {
		foreach ($this->actors as $actor) $actor->delete();
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY)
				: $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void real NC34/35 scheduling returns busy/free/unknown and the MCP policy only narrows it */
	public function testOneWindowReadsNativeFreeBusyWithoutChangingTheEvent(): void {
		if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'calendar')) {
			self::markTestSkipped('Calendar is not enabled; see cell provisioning evidence.');
		}
		[$actor, $guest] = [$this->account(), $this->account()];
		$uid = $actor->getUID();
		$this->service = Server::get(IConfig::class)->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		$client = new McpClient(LoginFactory::basic($uid));
		$args = ['participants' => [$guest->getUID(), 'outside@example.invalid'],
			'start' => '2027-06-01T12:00:00Z', 'end' => '2027-06-01T13:00:00Z', 'timezone' => 'UTC'];
		self::assertFalse($client->tool('calendar_check_availability', $args)->ok(), 'availability is opt-in even though calendar.read defaults on');
		$policy->setGrant($uid, 'calendar', 'availability', true);
		$credential = LoginFactory::basic($guest->getUID());
		$path = '/remote.php/dav/calendars/' . rawurlencode($guest->getUID()) . '/personal/mcp-busy.ics';
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MCP//Availability proof//EN\r\nBEGIN:VEVENT\r\nUID:mcp-busy\r\nDTSTAMP:20270101T000000Z\r\nDTSTART:20270601T120000Z\r\nDTEND:20270601T130000Z\r\nSUMMARY:Private title must stay hidden\r\nCLASS:PRIVATE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$headers = ['Authorization: ' . $credential->authorization()];
		$put = Http::request('PUT', Http::BASE . $path, [...$headers, 'Content-Type: text/calendar'], $ics);
		self::assertContains($put['status'], [201, 204]);
		$before = Http::request('GET', Http::BASE . $path, $headers);
		self::assertSame(200, $before['status']);
		$reply = $client->tool('calendar_check_availability', $args);
		self::assertTrue($reply->ok(), $reply->describe());
		self::assertSame([['participant' => $guest->getUID(), 'status' => 'busy'], ['participant' => 'outside@example.invalid', 'status' => 'unknown']], $reply->json()['participants']);
		self::assertStringNotContainsString('Private title', $reply->fingerprint());
		$free = $client->tool('calendar_check_availability', array_replace($args, ['start' => '2027-06-01T14:00:00Z', 'end' => '2027-06-01T15:00:00Z']));
		self::assertTrue($free->ok(), $free->describe());
		self::assertSame('free', $free->json()['participants'][0]['status']);
		$search = ['participants' => [$guest->getUID()], 'from' => '2027-06-01T12:00:00Z', 'to' => '2027-06-01T15:00:00Z',
			'timezone' => 'UTC', 'duration_minutes' => 60, 'step_minutes' => 30,
			'working_hours' => ['weekdays' => [2], 'start' => '12:00', 'end' => '15:00']];
		$suggest = $client->tool('calendar_suggest_times', $search);
		self::assertTrue($suggest->ok(), $suggest->describe());
		self::assertCount(3, $suggest->json()['suggestions']);
		self::assertSame('2027-06-01T13:00:00+00:00', $suggest->json()['suggestions'][0]['start']);
		self::assertSame(5, $suggest->json()['coverage']['examined']);
		self::assertFalse($suggest->json()['truncated']);
		$unknown = $client->tool('calendar_suggest_times', array_replace($search, ['participants' => [$guest->getUID(), 'outside@example.invalid']]));
		self::assertTrue($unknown->ok(), $unknown->describe());
		self::assertSame([], $unknown->json()['suggestions']);
		self::assertSame(5, $unknown->json()['coverage']['unknown_windows']);
		$after = Http::request('GET', Http::BASE . $path, $headers);
		self::assertSame(200, $after['status']);
		self::assertSame($before['body'], $after['body'], 'free/busy does not change the event');
		Server::get(PolicyWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin', $uid, 'calendar', []);
		$restricted = $client->tool('calendar_check_availability', $args);
		self::assertFalse($restricted->ok(), 'collection scope must refuse even when the native API could answer');
		self::assertFalse($client->tool('calendar_suggest_times', $search)->ok(), 'the same scope also refuses the suggestion tool');
	}

	/** @return IUser freshly provisioned local account, never a shared fixture identity */
	private function account(): IUser {
		$uid = 'mcp-it-freebusy-' . bin2hex(random_bytes(4));
		$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $user);
		$this->actors[] = $user;
		$user->setSystemEMailAddress($uid . '@example.invalid');
		Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($user));
		return $user;
	}
}
