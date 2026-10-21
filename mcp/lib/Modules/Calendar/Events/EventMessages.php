<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Events;

use OCA\Mcp\L10n\Translator;
final class EventMessages
{
    public const DAV_FAILURE = 'Calendar DAV failure';
    public static function allDayNeedsDates(): string
    {
        return Translator::t('Provide start and end when changing allDay.');
    }
    public static function conflict(string $reason): string
    {
        return Translator::t('Conflict: %s', [$reason]);
    }
    public static function conflictEtag(): string
    {
        return Translator::t('the event was changed by another person (etag diverged).');
    }
    public static function conflictUid(): string
    {
        return Translator::t('an event with the same UID already exists in the destination calendar.');
    }
    public static function eventUnreadable(): string
    {
        return Translator::t('The event has invalid data and cannot be changed.');
    }
    public static function expansionLimit(): string
    {
        return Translator::t('Occurrence expansion limit reached; reduce the calendar window.');
    }
    public static function forbidden(): string
    {
        return Translator::t('No permission to change this calendar or event.');
    }
    public static function invalidAllDayFormat(string $arg): string
    {
        return Translator::t('Use YYYY-MM-DD format in %s for all-day event.', [$arg]);
    }
    public static function invalidTimeZone(): string
    {
        return Translator::t('Invalid time zone in timeZone.');
    }
    public static function noFieldGiven(): string
    {
        return Translator::t('Provide at least one field to change.');
    }
    public static function notFound(): string
    {
        return Translator::t('Calendar or event not found.');
    }
    public static function notOrganizer(): string
    {
        return Translator::t('only the event organizer can change attendees.');
    }
    public static function occurrenceLimit(): string
    {
        return Translator::t('Limit of 500 occurrences per series reached; reduce the calendar window.');
    }
    public static function rangeEndBeforeStart(): string
    {
        return Translator::t('Invalid range: end must be after start.');
    }
    public static function rangeFromAfterTo(): string
    {
        return Translator::t('Invalid range: from must be before to.');
    }
    public static function recurringAttendees(): string
    {
        return Translator::t('attendees cannot be changed in a recurring series.');
    }
    public static function recurringTiming(): string
    {
        return Translator::t('changing dates of a recurring series is not supported.');
    }
    public static function trashDisabled(): string
    {
        return Translator::t('Deletion blocked: the calendar trash bin is disabled and the event would not be recoverable.');
    }
    public static function windowTooWide(): string
    {
        return Translator::t('Calendar window exceeds the limit of 366 days.');
    }
}
