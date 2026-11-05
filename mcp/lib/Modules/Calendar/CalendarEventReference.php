<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Access\References\ReadableCalendarEvent;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Platform\Dav\CalDavObjects;
use OCA\Mcp\Platform\References\ReferenceRead;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;

/** Projects an event through the Calendar module's existing scope, parsing and classification rules. */
final class CalendarEventReference implements ReadableCalendarEvent {
	/**
	 * @param ScopedCalendarLookup $calendars the existing scoped resolver, never the raw backend
	 * @param CalDavObjects $calendarStore existing object read port
	 * @param EventRepository $events existing tolerant event parser
	 * @param Classification $classification existing complete-object classification
	 */
	public function __construct(
		private ScopedCalendarLookup $calendars,
		private CalDavObjects $calendarStore,
		private EventRepository $events,
		private Classification $classification,
	) {
	}

	/** {@inheritDoc} */
	public function read(string $userId, string $path, string $uid): ReferenceRead {
		try {
			$calendar = $this->calendars->resolve($userId, $path);
			$row = $this->calendarStore->objectByUid($calendar->id, $uid, 'VEVENT');
			$vcalendar = ($row === null || $row['deleted']) ? null : $this->events->parse($row['data']);
		} catch (CalendarException $e) {
			return new ReferenceRead(null, $e);
		}
		if ($row === null || $vcalendar === null) {
			return new ReferenceRead();
		}
		$visibility = $this->classification->visibility($vcalendar, $calendar, $userId);
		if ($visibility === Classification::HIDDEN) {
			return new ReferenceRead();
		}
		$summary = '';
		foreach ($vcalendar->select('VEVENT') as $event) {
			if (!isset($event->{'RECURRENCE-ID'})) {
				$summary = (string)($event->SUMMARY ?? '');
			}
		}
		$title = $visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : $summary;
		return new ReferenceRead(['title' => $title, 'path' => $calendar->path, 'uri' => (string)$row['uri']]);
	}
}
