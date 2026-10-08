<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * A live calendar object located by UID, with its parsed content.
 */
final class StoredEvent {
    /**
     * @param int $id backend object id
     * @param string $uri object URI inside the calendar
     * @param string $etag current ETag (quoted, as the backend returns it)
     * @param VCalendar $vcalendar parsed content
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uri,
        public readonly string $etag,
        public readonly VCalendar $vcalendar,
    ) {}

    /**
     * @return VEvent|null the VEVENT without RECURRENCE-ID
     */
    public function master(): ?VEvent {
        foreach ($this->vcalendar->select('VEVENT') as $event) {
            if (!isset($event->{'RECURRENCE-ID'})) {
                return $event;
            }
        }
        return null;
    }

    /**
     * @return bool whether the object describes a recurring series or has overrides
     */
    public function recurring(): bool {
        foreach ($this->vcalendar->select('VEVENT') as $event) {
            if (isset($event->RRULE) || isset($event->RDATE) || isset($event->{'RECURRENCE-ID'})) {
                return true;
            }
        }
        return false;
    }
}
