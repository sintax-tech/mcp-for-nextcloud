<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Events;

use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\StoredEvent;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
/**
 * Finds events by UID for changes, enforcing classification and optimistic concurrency.
 */
class EventRepository
{
    /**
     * @param CalendarStore $store calendar storage port
     * @param Classification $classification CLASS rules for other owners' calendars
     */
    public function __construct(private CalendarStore $store, private Classification $classification)
    {
    }
    /**
     * Loads an event the user is about to change.
     *
     * @param Calendar $calendar calendar already resolved for the user
     * @param string $uid event UID
     * @param string $userId acting user
     * @param string|null $etag expected ETag, when the client sent one
     * @return StoredEvent
     * @throws CalendarException when missing, hidden, confidential, unreadable or changed meanwhile
     */
    public function forChange(
        Calendar $calendar,
        string $uid,
        string $userId,
        ?string $etag,
    ): StoredEvent
    {
        $row = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
        if ($row === null || $row['deleted']) {
            throw CalendarException::notFound();
        }
        $vcalendar = $this->parse($row['data']);
        if ($vcalendar === null) {
            throw CalendarException::blocked(\OCA\Mcp\Modules\Calendar\Events\EventMessages::eventUnreadable());
        }
        $this->classification->assertModifiable($vcalendar, $calendar, $userId);
        if ($etag !== null && trim($etag, '"') !== trim($row['etag'], '"')) {
            throw CalendarException::conflict(\OCA\Mcp\Modules\Calendar\Events\EventMessages::conflictEtag());
        }
        return new StoredEvent(
            $row['id'],
            $row['uri'],
            $row['etag'],
            $vcalendar
        );
    }
    /**
     * @param Calendar $calendar target calendar
     * @param string $uid event UID
     * @param string $uri object URI
     * @throws CalendarException when the UID or the URI is already taken in the calendar
     */
    public function assertFree(
        Calendar $calendar,
        string $uid,
        string $uri,
    ): void
    {
        if ($this->store->objectByUid($calendar->id, $uid, 'VEVENT') !== null || $this->store->object($calendar->id, $uri) !== null) {
            throw CalendarException::conflict(\OCA\Mcp\Modules\Calendar\Events\EventMessages::conflictUid());
        }
    }
    /**
     * @param string $data serialized iCalendar
     * @return VCalendar|null null when the data is not a valid VCALENDAR
     */
    public function parse(string $data): ?VCalendar
    {
        try {
            $parsed = Reader::read($data, Reader::OPTION_FORGIVING);
        } catch (ParseException) {
            return null;
        }
        return $parsed instanceof VCalendar ? $parsed : null;
    }
}
