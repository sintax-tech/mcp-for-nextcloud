<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{Http, LoginFactory, McpClient};
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Xml\Property\Href;

/** Real endpoint plans for a mixed-case German account with owned, subscribed and shared calendars. */
final class McpCalendarPlanEnvironmentHttpTest extends IntegrationTestCase {
	/** @var list<IUser> Only accounts created by this test. */
	private array $actors = [];
	/** Previous service preference, restored after the isolated proof. */
	private ?string $service = null;

	/** @return void Removes the test accounts and restores the service preference, even after failure. */
	protected function tearDown(): void {
		foreach (array_reverse($this->actors) as $actor) { $actor->delete(); }
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY)
				: $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void Both authentication modes return plans without changing any of the three writable calendars. */
	public function testCreateAndDescriptionUpdatePlansForMixedCaseGermanAccount(): void {
		$suffix = bin2hex(random_bytes(4));
		$user = $this->account('Tester' . $suffix);
		$owner = $this->account('Owner' . $suffix);
		$uid = $user->getUID();
		self::assertStringStartsWith('Tester', $uid);
		$config = Server::get(IConfig::class);
		$config->setUserValue($uid, 'core', 'lang', 'de');
		$config->setUserValue($uid, 'core', 'timezone', 'Europe/Berlin');
		$this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		foreach (['read', 'create', 'edit'] as $operation) { $policy->setGrant($uid, 'calendar', $operation, true); }

		$backend = Server::get(CalDavBackend::class);
		$principal = 'principals/users/' . $uid;
		$backend->createCalendar($principal, 'second', ['{DAV:}displayname' => 'Neutral second calendar']);
		$backend->createSubscription($principal, 'subscription', [
			'{http://calendarserver.org/ns/}source' => new Href('https://example.invalid/neutral.ics'),
			'{DAV:}displayname' => 'Neutral subscription',
		]);
		self::assertCount(1, $backend->getSubscriptionsForUser($principal));
		$ownerPrincipal = 'principals/users/' . $owner->getUID();
		$backend->createCalendar($ownerPrincipal, 'shared', ['{DAV:}displayname' => 'Neutral shared calendar']);
		$owned = array_values(array_filter($backend->getCalendarsForUser($ownerPrincipal), static fn (array $row): bool => $row['uri'] === 'shared'));
		self::assertCount(1, $owned);
		$node = new Calendar($backend, $owned[0], Server::get(IFactory::class)->get('dav'), $config, Server::get(LoggerInterface::class));
		$backend->updateShares($node, [['href' => 'principal:' . $principal, 'commonName' => 'Neutral tester', 'readOnly' => false]], []);

		$dav = LoginFactory::basic($uid);
		$clients = [new McpClient($dav), new McpClient(LoginFactory::bearer($uid))];
		$list = $clients[0]->tool('calendar_list_calendars');
		self::assertTrue($list->ok(), $list->describe());
		$calendars = array_values(array_filter($list->json(), static fn (array $calendar): bool => $calendar['writable']));
		self::assertCount(3, $calendars, 'Personal, second and shared calendars are writable');
		foreach ($calendars as $calendar) {
			$path = $calendar['path'];
			$object = $path . 'neutral-seed.ics';
			$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Neutral fixture//EN\r\nBEGIN:VEVENT\r\nUID:neutral-seed\r\nDTSTAMP:20310101T000000Z\r\nSUMMARY:Neutral seed\r\nDTSTART:20310601T090000Z\r\nDTEND:20310601T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
			$put = Http::request('PUT', Http::BASE . $object, ['Authorization: ' . $dav->authorization(), 'Content-Type: text/calendar'], $ics);
			self::assertSame(201, $put['status'], 'Seed through the official CalDAV endpoint');
			$before = Http::request('GET', Http::BASE . $object, ['Authorization: ' . $dav->authorization()]);
			self::assertSame(200, $before['status']);
			foreach ($clients as $client) {
				foreach ([
					['start' => '2031-06-02T10:00:00+02:00', 'end' => '2031-06-02T11:00:00+02:00'],
					['start' => '2031-06-02', 'end' => '2031-06-03', 'allDay' => true],
					['start' => '2031-06-02T08:00:00Z', 'end' => '2031-06-02T09:00:00Z', 'timeZone' => 'Europe/Berlin'],
				] as $dates) {
					$reply = $client->tool('calendar_create_event', ['calendar' => $path, 'summary' => 'Neutral proposal'] + $dates);
					self::assertTrue($reply->ok(), $reply->describe());
					self::assertTrue($reply->structured()['requiresConfirmation']);
					self::assertSame('calendar_create_event', $reply->structured()['action']);
				}
				$reply = $client->tool('calendar_update_event', ['calendar' => $path, 'uid' => 'neutral-seed', 'description' => 'Neutral proposed description']);
				self::assertTrue($reply->ok(), $reply->describe());
				self::assertTrue($reply->structured()['requiresConfirmation']);
				self::assertSame('Neutral proposed description', $reply->structured()['after']['description']);
			}
			$after = Http::request('GET', Http::BASE . $object, ['Authorization: ' . $dav->authorization()]);
			self::assertSame($before['body'], $after['body'], 'Description-only preview never writes the stored event');
		}
	}

	/**
	 * @param string $uid Neutral mixed-case account ID unique to the test.
	 * @return IUser Disposable enabled account with its native first-login calendars.
	 */
	private function account(string $uid): IUser {
		$user = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
		self::assertNotFalse($user);
		$this->actors[] = $user;
		$user->setSystemEMailAddress(strtolower($uid) . '@example.invalid');
		Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($user));
		return $user;
	}
}
