<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Locating\Calendar;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * What the Calendar selftest reads back to prove an effect: the accounts it runs for, the live objects and sync tokens
 * of its calendars, and the copies and inbox items of a participant. It only reads; an unproved effect stops the run.
 */
final class SelftestEvidence {
	/**
	 * @param IUserManager $users the accounts the diagnostic is asked to run for
	 * @param IAppManager $apps whether Calendar is enabled for an account
	 * @param CalendarStore $store the live calendar objects
	 * @param CalendarSelftestReader $reader backend state the tool results do not expose
	 * @param CalendarAccess $access the calendars a participant sees
	 */
	public function __construct(
		private IUserManager $users,
		private IAppManager $apps,
		private CalendarStore $store,
		private CalendarSelftestReader $reader,
		private CalendarAccess $access,
	) {
	}

	/**
	 * Stops the diagnostic when an effect is not proved.
	 *
	 * @param bool $condition whether the effect was observed
	 * @param string $message safe message of the unconfirmed effect
	 * @return void nothing when the condition holds
	 * @throws \RuntimeException carrying the safe message when it does not
	 */
	public function require(bool $condition, string $message): void {
		if (!$condition) { throw new \RuntimeException($message); }
	}

	/**
	 * @param string $uid internal account id given on the command line
	 * @return IUser validated internal account with Calendar and e-mail
	 * @throws \RuntimeException when the account is missing, disabled, without e-mail or without Calendar
	 */
	public function account(string $uid): IUser {
		$user = $this->users->get($uid);
		$this->require($user !== null && $user->isEnabled() && filter_var($user->getEMailAddress(), FILTER_VALIDATE_EMAIL) !== false && $this->apps->isEnabledForUser('calendar', $user), CalendarSelftestMessages::INVALID_USER);
		return $user;
	}

	/**
	 * @param string $uid internal account id of the attendee or ACL probe
	 * @param string $organizer the organizer it must differ from
	 * @return IUser validated account distinct from organizer
	 * @throws \RuntimeException when it is the organizer or not a valid account
	 */
	public function otherAccount(string $uid, string $organizer): IUser {
		$this->require($uid !== $organizer, CalendarSelftestMessages::INVALID_ATTENDEE);
		return $this->account($uid);
	}

	/**
	 * @param Calendar $calendar calendar that must hold the event
	 * @param string $uid generated event UID
	 * @return array<string,mixed> live readback by generated UID
	 * @throws \RuntimeException when the event is missing or trashed
	 */
	public function live(Calendar $calendar, string $uid): array {
		$row = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
		$this->require($row !== null && !$row['deleted'], CalendarSelftestMessages::assertion('live-object'));
		return $row;
	}

	/**
	 * @param Calendar $calendar calendar created by the diagnostic
	 * @return int fresh monotonically increasing backend sync token
	 * @throws \RuntimeException when the calendar is gone or its token is not numeric
	 */
	public function token(Calendar $calendar): int {
		$state = $this->reader->calendarState($calendar);
		$this->require($state !== null && ctype_digit($state['syncToken']) && !$state['deleted'], CalendarSelftestMessages::assertion('live-sync'));
		return (int)$state['syncToken'];
	}

	/**
	 * @param string $data iCalendar text stored by the backend
	 * @return VCalendar parsed backend data
	 * @throws \RuntimeException when the data is not a calendar
	 */
	public function parse(string $data): VCalendar {
		$calendar = Reader::read($data);
		$this->require($calendar instanceof VCalendar, CalendarSelftestMessages::FAILED);
		return $calendar;
	}

	/**
	 * @param array<string, mixed> $row stored event row to inspect
	 * @return void checks a real attendee survived with no scheduler status
	 * @throws \RuntimeException when the attendee is gone or was scheduled
	 */
	public function assertSuppressed(array $row): void {
		$guests = $this->parse($row['data'])->VEVENT->select('ATTENDEE');
		$this->require(count($guests) === 1, CalendarSelftestMessages::assertion('attendee-preserved'));
		foreach ($guests as $guest) {
			$this->require((string)$guest === 'mailto:mcp-selftest@example.invalid' && !isset($guest['SCHEDULE-STATUS']), CalendarSelftestMessages::assertion('suppressed'));
		}
	}

	/**
	 * @param string $uid participant account whose calendars are searched
	 * @param string $eventUid generated event UID
	 * @return list<array{calendar:Calendar,row:array}> participant-owned copies of this generated UID
	 */
	public function copies(string $uid, string $eventUid): array {
		$copies = [];
		foreach ($this->access->visible($uid) as $calendar) {
			if ($calendar->ownerId === $uid && ($row = $this->store->objectByUid($calendar->id, $eventUid, 'VEVENT')) !== null) { $copies[] = ['calendar' => $calendar, 'row' => $row]; }
		}
		return $copies;
	}

	/**
	 * @param string $uid account whose CalDAV inbox is read
	 * @param string $eventUid generated event UID
	 * @param string|null $method iTIP METHOD the item must carry, or null for any
	 * @return list<array{uri:string,data:string,etag:string}> only inbox objects with our UID and optional METHOD
	 */
	public function inbox(string $uid, string $eventUid, ?string $method = null): array {
		$matches = [];
		foreach ($this->reader->schedulingObjects($uid) as $row) {
			$ical = $this->parse($row['data']);
			if ($ical->VEVENT !== null && (string)$ical->VEVENT->UID === $eventUid && ($method === null || (string)$ical->METHOD === $method)) { $matches[] = $row; }
		}
		return $matches;
	}
}
