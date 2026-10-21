<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Scheduling;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
final class SchedulingMessages
{
    public static function attendeeBusy(string $name): string
    {
        return Translator::t('%s is busy at this time.', [PlanText::flat($name, 80)]);
    }
    public static function attendeeNotFound(string $uid): string
    {
        return Translator::t('attendee \'%s\' not found or has no email address', [$uid]);
    }
    public static function attendeeUnverifiable(string $name): string
    {
        return Translator::t('Could not check the availability of %s.', [PlanText::flat($name, 80)]);
    }
    public static function attendeesInvalid(): string
    {
        return Translator::t('Provide 1 to 50 attendees, without duplicates.');
    }
    public static function availabilityFailed(): string
    {
        return Translator::t('Could not check the participants\' availability.');
    }
    public static function calendarNotShared(
        string $calendar,
        string $names,
        ?string $suggested,
        bool $ask,
    ): string
    {
        $text = Translator::t('The calendar %s is not shared with %s.', [PlanText::flat($calendar, 80), PlanText::flat($names, 200)]);
        if ($suggested !== null) {
            return $text . ' ' . Translator::t('Suggested: %s.', [PlanText::flat($suggested, 80)]);
        }
        return $ask ? $text . ' ' . Translator::t('Ask the user which calendar to use.') : $text;
    }
    public static function collisionBusy(string $when): string
    {
        return Translator::t('Overlaps a busy appointment (%s)', [$when]);
    }
    public static function collisionMore(int $more): string
    {
        return Translator::t('+ %d more', [$more]);
    }
    public static function collisionWith(string $summary, string $when): string
    {
        return Translator::t('Overlaps %s (%s)', [PlanText::flat($summary, 120), $when]);
    }
    public static function imipDisabled(): string
    {
        return Translator::t('server has email invitation sending disabled');
    }
    public static function organizerWithoutEmail(): string
    {
        return Translator::t('your account has no email address, so the event cannot have attendees.');
    }
    public static function participantsNotNotified(): string
    {
        return Translator::t('Event attendees are not notified, just like when moving in the Calendar app.');
    }
    public static function scheduleStatus(string $code): string
    {
        return match (true) {
            str_starts_with($code, '1.1') => Translator::t('delivered to Nextcloud email sending; receipt is not confirmed'),
            str_starts_with($code, '1.2') => Translator::t('delivered to the internal attendee calendar'),
            str_starts_with($code, '1.0') => Translator::t('change without relevance; nothing sent'),
            str_starts_with($code, '3'), str_starts_with($code, '5') => Translator::t('delivery failure (%s)', [$code]),
            default => Translator::t('no delivery record'),
        };
    }
    public static function schedulingHandedOver(): string
    {
        return Translator::t('invitation delivered to Nextcloud scheduling');
    }
    public static function schedulingSuppressed(): string
    {
        return Translator::t('no invitations were scheduled');
    }
}
