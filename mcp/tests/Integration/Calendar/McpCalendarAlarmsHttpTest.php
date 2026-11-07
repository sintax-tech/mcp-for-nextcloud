<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{Credential, Http, LoginFactory, McpClient};
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use PHPUnit\Framework\Attributes\Group;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** Bearer create/update with two reminders, visible plans and independent authenticated CalDAV readback. */
#[Group('calendar')]
final class McpCalendarAlarmsHttpTest extends IntegrationTestCase {
	/** Disposable actor, removed even after a failed proof. */
	private ?IUser $actor = null;
	/** Previous global service value, restored on teardown. */
	private ?string $service = null;

	/** Removes only this test's actor and restores the global service switch.
	 * @return void
	 */
	protected function tearDown(): void {
		$this->actor?->delete();
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY)
				: $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** Refuses a missing create grant, displays and stores two reminders, then clears them with an explicit empty list.
	 * @return void
	 */
	public function testBearerTwoRemindersPlanReadbackAndClear(): void {
		if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'calendar')) {
			self::markTestSkipped('Calendar is not enabled; see the cell provisioning evidence.');
		}
		$uid = 'mcp-it-http-alarms-' . bin2hex(random_bytes(4));
		$this->actor = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24))) ?: null;
		self::assertNotNull($this->actor);
		$this->actor->setSystemEMailAddress($uid . '@example.invalid');
		Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($this->actor));
		$this->service = Server::get(IConfig::class)->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		$policy->setGrant($uid, 'calendar', 'edit', true);
		$client = new McpClient(LoginFactory::bearer($uid));
		$calendar = '/remote.php/dav/calendars/' . $uid . '/personal/';
		$dav = LoginFactory::basic($uid);
		$this->assertEmptyCalendar($calendar, $dav);
		$args = ['calendar' => $calendar, 'summary' => 'HTTP reminder proof',
			'start' => '2027-06-01', 'end' => '2027-06-02', 'allDay' => true,
			'alarms' => [['minutesBefore' => 15], ['minutesBefore' => 30]]];
		$policy->setGrant($uid, 'calendar', 'create', false);
		self::assertFalse($client->tool('calendar_create_event', $args)->ok());
		$this->assertEmptyCalendar($calendar, $dav);
		$policy->setGrant($uid, 'calendar', 'create', true);
		$plan = $client->tool('calendar_create_event', $args);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		self::assertSame([-900, -1800], array_column($plan->structured()['alarmChange']['after'], 'offsetSeconds'));
		self::assertStringContainsString('DISPLAY', $plan->text());
		self::assertStringContainsString('15', $plan->text());
		self::assertStringContainsString('30', $plan->text());
		$this->assertEmptyCalendar($calendar, $dav);
		$created = $client->tool('calendar_create_event', $args + ['confirm' => true]);
		self::assertTrue($created->ok(), $created->describe());
		$item = $created->json();
		self::assertSame('applied', $item['alarmReceipt']['status']);
		$path = $calendar . rawurlencode($item['uid']) . '.ics';
		$written = $this->readDav($path, $dav);
		$alarms = array_values($written->VEVENT->select('VALARM'));
		self::assertCount(2, $alarms);
		self::assertSame(['-PT15M', '-PT30M'], array_map(static fn ($a): string => (string)$a->TRIGGER, $alarms));
		foreach ($alarms as $alarm) {
			self::assertSame('DISPLAY', (string)$alarm->ACTION);
		}
		$update = ['calendar' => $calendar, 'uid' => $item['uid'], 'alarms' => []];
		$plan = $client->tool('calendar_update_event', $update);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertCount(2, $plan->structured()['alarmChange']['before']);
		self::assertSame([], $plan->structured()['alarmChange']['after']);
		$stillWritten = $this->readDav($path, $dav);
		self::assertCount(2, $stillWritten->VEVENT->select('VALARM'));
		self::assertSame($written->serialize(), $stillWritten->serialize(), 'Update preview must preserve the stored event');
		$cleared = $client->tool('calendar_update_event', $update + ['confirm' => true]);
		self::assertTrue($cleared->ok(), $cleared->describe());
		self::assertSame('applied', $cleared->json()['alarmReceipt']['status']);
		self::assertCount(0, $this->readDav($path, $dav)->VEVENT->select('VALARM'));
	}

	/** Checks the personal calendar has no event resources through an independent CalDAV query.
	 * @param string $path Disposable personal calendar URI.
	 * @param Credential $credential Actor's native app password.
	 * @return void
	 */
	private function assertEmptyCalendar(string $path, Credential $credential): void {
		$report = Http::request('REPORT', Http::BASE . $path,
			['Authorization: ' . $credential->authorization(), 'Depth: 1', 'Content-Type: application/xml; charset=utf-8'],
			'<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/>'
			. '</c:comp-filter></c:filter></c:calendar-query>');
		self::assertSame(207, $report['status']);
		$xml = new \DOMDocument();
		self::assertTrue($xml->loadXML($report['body'], LIBXML_NONET));
		$xpath = new \DOMXPath($xml);
		$xpath->registerNamespace('d', 'DAV:');
		self::assertSame(0, $xpath->query('/d:multistatus/d:response')->length, 'Plan/refusal must leave the calendar empty');
	}

	/** Reads the known event URI independently of the MCP calendar implementation.
	 * @param string $path Event URI returned by the create flow.
	 * @param Credential $credential Disposable actor's app password for CalDAV.
	 * @return VCalendar Parsed persisted event.
	 */
	private function readDav(string $path, Credential $credential): VCalendar {
		$get = Http::request('GET', Http::BASE . $path, ['Authorization: ' . $credential->authorization()]);
		self::assertSame(200, $get['status']);
		$calendar = Reader::read($get['body']);
		self::assertInstanceOf(VCalendar::class, $calendar);
		return $calendar;
	}
}
