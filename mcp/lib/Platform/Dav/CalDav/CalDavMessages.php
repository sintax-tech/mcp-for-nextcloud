<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

use OCA\Mcp\L10n\Translator;
final class CalDavMessages
{
    public static function notFound(): string
    {
        return Translator::t('Calendar or event not found.');
    }
    public const PATH_OBJECT = 'object';
    public static function conflictEtag(): string
    {
        return Translator::t('the event was changed by another person (etag diverged).');
    }
    public const PATH_CALENDAR = 'calendar';
    public static function eventTooLarge(int $limit): string
    {
        return Translator::t('The event exceeds the limit of %d bytes accepted by the server.', [$limit]);
    }
    public const DAV_FAILURE = 'Calendar DAV failure';
    public static function conflict(string $reason): string
    {
        return Translator::t('Conflict: %s', [$reason]);
    }
    public static function forbidden(): string
    {
        return Translator::t('No permission to change this calendar or event.');
    }
    public static function dataRefused(): string
    {
        return Translator::t('Nextcloud refused the event data.');
    }
    public static function conflictUid(): string
    {
        return Translator::t('an event with the same UID already exists in the destination calendar.');
    }
    public static function conflictName(): string
    {
        return Translator::t('an event with the same name already exists in the destination calendar.');
    }
    public static function invalidPathSegment(string $label): string
    {
        return Translator::t('Invalid %s identifier.', [$label]);
    }
    public const PATH_USER = 'user';
}
