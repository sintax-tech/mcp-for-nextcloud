<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Locating;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;

/**
 * Finds events by UID for changes, enforcing classification and optimistic concurrency.
 */
class EventRepository {
	/**
	 * @param CalendarStore $store calendar storage port
	 * @param Classification $classification CLASS rules for other owners' calendars
	 */
	public function __construct(
		private CalendarStore $store,
		private Classification $classification,
	) {}

	/**
	 * Loads an event the user is about to change.
	 *
	 * @param Calendar $calendar calendar already resolved for the user
	 * @param string $uid event UID
	 * @param string $userId acting user
	 * @param string|null $etag expected ETag, when the client sent one
	 * @return StoredEvent the event as stored, checked against classification and the expected ETag
	 * @throws CalendarException when missing, hidden, confidential, unreadable or changed meanwhile
	 */
	public function forChange(Calendar $calendar, string $uid, string $userId, ?string $etag): StoredEvent {
		// An object of another component (a task of a calendar that holds both) is as missing as an unknown UID.
		$row = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
		if ($row === null || $row['deleted']) {
			throw CalendarException::notFound();
		}
		$vcalendar = $this->parse($row['data']);
		if ($vcalendar === null) {
			throw CalendarException::blocked(CalendarMessages::eventUnreadable());
		}
		$this->classification->assertModifiable($vcalendar, $calendar, $userId);
		if ($etag !== null && trim($etag, '"') !== trim($row['etag'], '"')) {
			throw CalendarException::conflict(CalendarMessages::conflictEtag());
		}
		return new StoredEvent($row['id'], $row['uri'], $row['etag'], $vcalendar);
	}

	/**
	 * @param Calendar $calendar target calendar
	 * @param string $uid event UID
	 * @param string $uri object URI
	 * @throws CalendarException when the UID or the URI is already taken in the calendar
	 */
	public function assertFree(Calendar $calendar, string $uid, string $uri): void {
		if ($this->store->objectByUid($calendar->id, $uid, 'VEVENT') !== null || $this->store->object($calendar->id, $uri) !== null) {
			throw CalendarException::conflict(CalendarMessages::conflictUid());
		}
	}

	/**
	 * @param string $data serialized iCalendar
	 * @return VCalendar|null null when the data is not a valid VCALENDAR
	 */
	public function parse(string $data): ?VCalendar {
		try {
			$parsed = Reader::read($data, Reader::OPTION_FORGIVING);
		} catch (ParseException) {
			return null;
		}
		return $parsed instanceof VCalendar ? $parsed : null;
	}
}
