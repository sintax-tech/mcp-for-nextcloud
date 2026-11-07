<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * The scene of the Calendar write contracts beyond creation and plain update: three calendars alice can write (two of
 * her own, one bob shares with her read-write), accounts with e-mail addresses so events can carry guests, a CalDAV
 * double whose every request is kept with its arguments (the scheduling flag and If-Match included), and a stand-in for
 * the Nextcloud scheduler.
 *
 * The scheduler stand-in plays the part Sabre's schedule plugin has on the server: only a request dispatched with the
 * scheduling flag reaches it; it records what it was handed and, on a PUT, marks each guest delivered locally
 * (SCHEDULE-STATUS=1.2), which is what the module then reads back and reports. It never runs for a request sent with
 * the flag off, so a lost flag shows both in the request log and in the stored object.
 */
final class CalendarWritesScene {
	/** Principal of the account every call is made for. */
	public const ALICE = 'principals/users/alice';
	/** Principal of the owner of the shared calendar. */
	public const BOB = 'principals/users/bob';
	/** Path of the personal calendar of alice (id 1). */
	public const PERSONAL = '/remote.php/dav/calendars/alice/personal/';
	/** Path of the work calendar of alice (id 2). */
	public const WORK = '/remote.php/dav/calendars/alice/work/';
	/** Path of the calendar bob shares with alice read-write (id 3). */
	public const TEAM = '/remote.php/dav/calendars/alice/team_shared_by_bob/';
	/** Why no calendar flow has a visibility step. */
	public const NO_VISIBILITY = 'Calendar objects are not Files nodes: no system tag or excluded folder can hide a '
		. 'calendar or an event from MCP (VisibilityGuard and FilesAccessGuard do not apply). What narrows the reachable '
		. 'calendars is the administrator resource scope and the native ACL, which are scope and permission refusals, '
		. 'not visibility.';
	/** Display name and e-mail address of every account the scene knows. */
	private const ACCOUNTS = ['alice' => ['Alice Silva', 'alice@example.invalid'], 'bob' => ['Roberto Almeida', 'bob@example.invalid'],
		'carla' => ['Carla Dias', 'carla@example.invalid']];

	/** The calendars and objects, persistent across requests. */
	public FakeCalendarStore $store;
	/** The CalDAV double every write goes through, with its request log. */
	public FakeCalendarDav $dav;
	/** @var list<string> what the scheduler stand-in was handed, as "METHOD uid: guests" */
	public array $scheduled = [];

	/** Builds the calendars and the CalDAV double with the scheduler stand-in. */
	public function __construct() {
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Pessoal']);
		$this->store->addCalendar(self::ALICE, 2, 'work', self::ALICE, ['name' => 'Trabalho']);
		$this->store->addCalendar(self::ALICE, 3, 'team_shared_by_bob', self::BOB, ['name' => 'Equipe (bob)']);
		$this->dav = new FakeCalendarDav($this->store);
		$this->dav->schedule = function (string $ics, string $method): string {
			$calendar = Reader::read($ics);
			$event = $calendar->VEVENT;
			$guests = [];
			foreach ($event->select('ATTENDEE') as $attendee) {
				$guests[] = (string)$attendee;
				if ($method === 'PUT') {
					$attendee['SCHEDULE-STATUS'] = '1.2';
				}
			}
			$this->scheduled[] = $method . ' ' . $event->UID . ': ' . implode(', ', $guests);
			return $method === 'PUT' ? $calendar->serialize() : $ics;
		};
	}

	/**
	 * @param int $calendarId calendar the event goes to
	 * @param string $uid its UID; the object URI is "<uid>.ics"
	 * @param string $summary its title
	 * @param list<string> $guests account ids invited, with alice as the organizer; none leaves the event without guests
	 * @return void
	 */
	public function event(int $calendarId, string $uid, string $summary, array $guests = []): void {
		$lines = ['UID:' . $uid, 'DTSTAMP:20260301T100000Z', 'SUMMARY:' . $summary, 'DTSTART:20261001T120000Z', 'DTEND:20261001T130000Z', 'SEQUENCE:0'];
		if ($guests !== []) {
			$lines[] = 'ORGANIZER;CN=Alice Silva:mailto:alice@example.invalid';
			foreach ($guests as $guest) {
				$lines[] = 'ATTENDEE;CN=' . self::ACCOUNTS[$guest][0] . ';CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:'
					. self::ACCOUNTS[$guest][1];
			}
		}
		$this->store->addObject($calendarId, $uid . '.ics', str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\n"
			. "BEGIN:VEVENT\n" . implode("\n", $lines) . "\nEND:VEVENT\nEND:VCALENDAR\n"));
	}

	/**
	 * @param string $uid event UID
	 * @return int|null the calendar whose live objects hold it, or null when none does
	 */
	public function calendarOf(string $uid): ?int {
		foreach ($this->store->objects as $calendarId => $rows) {
			if (isset($rows[$uid . '.ics']) && !$rows[$uid . '.ics']['deleted']) {
				return $calendarId;
			}
		}
		return null;
	}

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @return IUserManager the accounts of the scene, each enabled and with its e-mail address
	 */
	public static function users(TestCase $test): IUserManager {
		$mock = static fn (string $class): MockObject => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$users = $mock(IUserManager::class);
		$users->method('get')->willReturnCallback(static function (string $uid) use ($mock): ?IUser {
			if (!isset(self::ACCOUNTS[$uid])) {
				return null;
			}
			$user = $mock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(self::ACCOUNTS[$uid][0]);
			$user->method('getEMailAddress')->willReturn(self::ACCOUNTS[$uid][1]);
			$user->method('isEnabled')->willReturn(true);
			return $user;
		});
		return $users;
	}

	/**
	 * @return array{objects: array<int, array<string, string>>, trash: array<int, array<string, string>>,
	 *     dav: list<array<string, mixed>>, scheduled: list<string>}
	 *     live and trashed iCalendar text per calendar id and object URI, every CalDAV request with its arguments (the
	 *     iCalendar text it carried, its If-Match, its scheduling flag), and what the scheduler was handed
	 */
	public function state(): array {
		$objects = [];
		$trash = [];
		foreach ($this->store->objects as $calendarId => $rows) {
			$objects[$calendarId] = [];
			foreach ($rows as $uri => $row) {
				if ($row['deleted']) {
					$trash[$calendarId][$uri] = $row['data'];
				} else {
					$objects[$calendarId][$uri] = $row['data'];
				}
			}
		}
		return ['objects' => $objects, 'trash' => $trash, 'dav' => array_map(self::request(...), $this->dav->calls), 'scheduled' => $this->scheduled];
	}

	/**
	 * @param array{0:string, 1:list<mixed>} $call one call FakeCalendarDav recorded
	 * @return array<string, mixed> the same call with its arguments named
	 */
	private static function request(array $call): array {
		[$operation, $arguments] = $call;
		$names = match ($operation) {
			'put' => ['calendar', 'object', 'ics', 'scheduling'],
			'update' => ['calendar', 'object', 'etag', 'ics', 'scheduling'],
			'delete' => ['calendar', 'object', 'etag', 'scheduling'],
			'move' => ['calendar', 'object', 'target', 'etag'],
			default => array_map(static fn (int $i): string => 'argument' . $i, array_keys($arguments)),
		};
		return ['operation' => $operation] + array_combine($names, $arguments);
	}
}
