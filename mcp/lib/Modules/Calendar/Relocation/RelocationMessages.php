<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Relocation;

use OCA\Mcp\L10n\Translator;
final class RelocationMessages
{
    public static function conflictSameCalendar(): string
    {
        return Translator::t('source and destination are the same calendar.');
    }
    public static function moveNeedsTransfer(): string
    {
        return Translator::t('The calendars have different owners; use calendar_transfer_event.');
    }
    public static function moveNotConfirmed(): string
    {
        return Translator::t('Could not move the event; try again.');
    }
    public static function transferNeedsMove(): string
    {
        return Translator::t('The calendars have the same owner; use calendar_move_event.');
    }
    public static function transferWithAttendees(): string
    {
        return Translator::t('Cannot transfer an event with attendees to someone else\'s calendar: you remain the organizer and Nextcloud does not notify anyone in this operation. Move the event within your calendars or remove attendees first.');
    }
}
