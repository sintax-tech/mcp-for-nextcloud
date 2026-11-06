<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
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
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** MCP writes checked by independent authenticated CalDAV GETs on a disposable account. */
final class CalendarAlarmsTest extends IntegrationTestCase {
	/** Disposable actor, removed even when the proof fails. */
	private ?IUser $actor = null;
	/** Global service value restored after the test. */
	private ?string $service = null;

	/** @return void remove only this test's account and restore the service setting */
	protected function tearDown(): void {
		$this->actor?->delete();
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY)
				: $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void real server readback proves create 1/5, external alarms, replacement, omission and clear */
	public function testCalendarRemindersRoundtripThroughCalDav(): void {
		if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'calendar')) {
			self::markTestSkipped('Calendar is not enabled; see the cell provisioning evidence.');
		}
		$uid = 'mcp-it-alarms-' . bin2hex(random_bytes(4));
		$this->actor = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24))) ?: null;
		self::assertNotNull($this->actor);
		$this->actor->setSystemEMailAddress($uid . '@example.invalid');
		Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($this->actor));
		$this->service = Server::get(IConfig::class)->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		foreach (['create', 'edit'] as $operation) {
			$policy->setGrant($uid, 'calendar', $operation, true);
		}
		$credential = LoginFactory::basic($uid);
		$client = new McpClient($credential);
		$calendar = '/remote.php/dav/calendars/' . $uid . '/personal/';
		foreach ([1, 5] as $count) {
			$alarms = array_map(static fn (int $n): array => ['minutesBefore' => $n * 15], range(1, $count));
			$reply = $client->write('calendar_create_event', [
				'calendar' => $calendar, 'summary' => 'Disposable reminder proof',
				'start' => '2027-06-01', 'end' => '2027-06-02', 'allDay' => true, 'alarms' => $alarms,
			]);
			self::assertTrue($reply->ok(), $reply->describe());
			$item = $reply->json();
			$path = $calendar . rawurlencode($item['uid']) . '.ics';
			$written = $this->readDav($path, $credential);
			self::assertCount($count, $written->VEVENT->select('VALARM'));
			self::assertSame('applied', $item['alarmReceipt']['status']);
		}
		// Seed reminders with a separate client, without calling the MCP composer.
		$event = $written->VEVENT;
		$event->add('VALARM', ['ACTION' => 'AUDIO', 'TRIGGER' => '-PT2M', 'ATTACH' => 'https://example.invalid/sound']);
		$event->add('VALARM', ['ACTION' => 'EMAIL', 'TRIGGER' => '-PT3M', 'SUMMARY' => 'External',
			'DESCRIPTION' => 'External', 'ATTENDEE' => 'mailto:' . $uid . '@example.invalid']);
		$absolute = $event->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => 'Absolute']);
		$absolute->add('TRIGGER', '20270601T080000Z', ['VALUE' => 'DATE-TIME']);
		$end = $event->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => 'End']);
		$end->add('TRIGGER', '-PT4M', ['RELATED' => 'END']);
		$put = Http::request('PUT', Http::BASE . $path, [
			'Authorization: ' . $credential->authorization(), 'Content-Type: text/calendar',
		], $written->serialize());
		self::assertContains($put['status'], [201, 204]);
		$seed = $this->readDav($path, $credential);
		$kept = EventAlarms::fingerprints($seed, false);
		self::assertCount(4, $kept);
		$args = ['calendar' => $calendar, 'uid' => $item['uid']];
		$reply = $client->write('calendar_update_event', $args + ['alarms' => [['minutesBefore' => 10]]]);
		self::assertTrue($reply->ok(), $reply->describe());
		$after = $this->readDav($path, $credential);
		self::assertCount(1, EventAlarms::inspect($after)['managed']);
		self::assertSame(-600, EventAlarms::inspect($after)['managed'][0]['offsetSeconds']);
		self::assertSame($kept, EventAlarms::fingerprints($after, false));
		$reply = $client->write('calendar_update_event', $args + ['summary' => 'No alarms parameter']);
		self::assertTrue($reply->ok(), $reply->describe());
		$omitted = $this->readDav($path, $credential);
		self::assertSame(EventAlarms::fingerprints($after, true), EventAlarms::fingerprints($omitted, true));
		self::assertSame($kept, EventAlarms::fingerprints($omitted, false));
		$reply = $client->write('calendar_update_event', $args + ['alarms' => []]);
		self::assertTrue($reply->ok(), $reply->describe());
		$cleared = $this->readDav($path, $credential);
		self::assertSame([], EventAlarms::inspect($cleared)['managed']);
		self::assertSame($kept, EventAlarms::fingerprints($cleared, false));
	}

	/**
	 * @param string $path known event URI returned by the create flow
	 * @param Credential $credential authenticated disposable actor
	 * @return VCalendar independent HTTP readback
	 */
	private function readDav(string $path, Credential $credential): VCalendar {
		$get = Http::request('GET', Http::BASE . $path, ['Authorization: ' . $credential->authorization()]);
		self::assertSame(200, $get['status']);
		$calendar = Reader::read($get['body']);
		self::assertInstanceOf(VCalendar::class, $calendar);
		return $calendar;
	}
}
