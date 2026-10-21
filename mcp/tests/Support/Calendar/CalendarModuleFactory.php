<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Calendar;
final class CalendarModuleFactory {
    public static function create($listCalendars, $listEvents, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent, $approval, $references): \OCA\Mcp\Modules\Calendar\CalendarModule {
        return new \OCA\Mcp\Modules\Calendar\CalendarModule([
            'calendar_list_calendars' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\ListCalendars($listCalendars),
            'calendar_list_events' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\ListEvents($listEvents),
            'calendar_create_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\CreateEvent($createEvent, $approval),
            'calendar_update_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\UpdateEvent($updateEvent, $approval),
            'calendar_move_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\MoveEvent($moveEvent, $approval),
            'calendar_delete_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\DeleteEvent($deleteEvent, $approval),
            'calendar_transfer_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\TransferEvent($transferEvent, $approval, $references),
        ], ($references)->assertAvailable(...));
    }
}
