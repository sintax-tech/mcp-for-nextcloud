<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\References;

use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceNotFound;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCP\IURLGenerator;
class CalendarEventReferenceReader implements EventReferenceReader
{
    public function __construct(
        private CalendarAccess $calendars,
        private CalendarStore $calendarStore,
        private EventRepository $events,
        private Classification $classification,
        private IURLGenerator $urlGenerator,
    )
    {
    }
    public function read(
        string $userId,
        string $calendarPath,
        string $uid,
    ): array
    {
        $path = $calendarPath;
        try {
            // The calendar tools' own rules: the path must be one of the caller's visible calendars, and the
            // iCalendar CLASS of an event in someone else's calendar decides what the caller may see of it.
            $calendar = $this->calendars->resolve($userId, $path);
            $row = $this->calendarStore->objectByUid($calendar->id, $uid, 'VEVENT');
            $vcalendar = $row === null || $row['deleted'] ? null : $this->events->parse($row['data']);
        } catch (CalendarException|\OCA\Mcp\Platform\Dav\CalDav\CalDavFailure $e) {
            throw new EventReferenceNotFound('Event reference not found', 0, $e);
        }
        if ($row === null || $vcalendar === null) {
            throw new EventReferenceNotFound('Event reference not found');
        }
        $visibility = $this->classification->visibility($vcalendar, $calendar, $userId);
        if ($visibility === Classification::HIDDEN) {
            throw new EventReferenceNotFound('Event reference not found');
        }
        $summary = '';
        foreach ($vcalendar->select('VEVENT') as $event) {
            if (!isset($event->{'RECURRENCE-ID'})) {
                $summary = (string) ($event->SUMMARY ?? '');
            }
        }
        // A confidential event is shown as busy by the calendar tools, and the link is not a way around that.
        $title = $visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : $summary;
        // The deep link the server's own event search builds: the Calendar app opens the object from the base64
        // of its DAV URL, and it works for shared calendars because the path is the caller's own view of it.
        $davUrl = $this->urlGenerator->getWebroot() . $calendar->path . rawurlencode((string) $row['uri']);
        return [
            'title' => $title,
            'url' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('calendar.view.index') . 'edit/' . base64_encode($davUrl)),
        ];
    }
}
