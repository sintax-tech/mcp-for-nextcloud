<?php
declare(strict_types=1);

namespace OCA\Mcp\Service\Calendar;

use OCA\Mcp\L10n\Translator;

/** Messages of the Calendar selftest command and runner. */
final class CalendarSelftestMessages {
    public const ASSERTION_PREFIX = 'Unconfirmed effect:';
    public const DESCRIPTION = 'Verifies Calendar writes through real DAV and opens the gate after cleanup.';
    public const UID = 'Internal UID of the test organizer (enabled account with email and Calendar).';
    public const ATTENDEE = 'Another internal UID: proves iTIP on calendar and CalDAV inbox; may generate email according to dav/sendInvitations.';
    public const SHARED = 'Writable shared calendar path from another owner; proves transfer and deletes only the test event.';
    public const ACL = 'Another UID without access to test calendars; proves write refusal.';
    public const NO_ENABLE = 'Runs the proof and cleanup without recording enablement; revokes prior enablement.';
    public const REVOKE = 'Deletes verification and hides all writes, without creating objects.';
    public const REVOKED = 'Verification revoked; Calendar writes hidden.';
    public const INVALID_USER = 'The account must exist, be enabled, have an email, and have the Calendar app enabled.';
    public const INVALID_ATTENDEE = 'The attendee and the ACL probe user must be enabled internal accounts, distinct from the organizer.';
    public const TRASH_REQUIRED = 'Calendar retention is set to 0. The test was refused before creating objects because cleanup would be permanent.';
    public const FAILED = 'Verification failed; no writes were enabled.';
    public const PASSED = 'Proof completed and cleanup confirmed; verification recorded.';
    public const PASSED_NO_ENABLE = 'Proof completed and cleanup confirmed; verification not recorded (--no-enable).';
    public const EVENT = 'MCP Calendar selftest';
    public const EVENT_EDITED = 'MCP Calendar selftest edited';
    public const OPTIONAL = 'Option not provided; capability not verified.';
    public const SUMMARY = 'Confirm Activity, CalDAV clients, and email delivery manually; the report does not confirm email delivery.';

    public static function revoked(): string {
        return Translator::t('Verification revoked; Calendar writes hidden.');
    }

    public static function invalidUser(): string {
        return Translator::t('The account must exist, be enabled, have an email, and have the Calendar app enabled.');
    }

    public static function invalidAttendee(): string {
        return Translator::t('The attendee and the ACL probe user must be enabled internal accounts, distinct from the organizer.');
    }

    public static function trashRequired(): string {
        return Translator::t('Calendar retention is set to 0. The test was refused before creating objects because cleanup would be permanent.');
    }

    public static function failed(): string {
        return Translator::t('Verification failed; no writes were enabled.');
    }

    public static function passed(): string {
        return Translator::t('Proof completed and cleanup confirmed; verification recorded.');
    }

    public static function passedNoEnable(): string {
        return Translator::t('Proof completed and cleanup confirmed; verification not recorded (--no-enable).');
    }

    public static function optional(): string {
        return Translator::t('Option not provided; capability not verified.');
    }

    public static function summary(): string {
        return Translator::t('Confirm Activity, CalDAV clients, and email delivery manually; the report does not confirm email delivery.');
    }

    /** @return string safe stage evidence assembled from generated identifiers only */
    public static function detail(string $step, mixed ...$values): string {
        $format = match ($step) {
            'calendars' => 'HTTP 201; %s %s',
            'create' => 'HTTP 201; UID %s; sync-token %s',
            'suppression-create' => 'HTTP %s; ATTENDEE @example.invalid without SCHEDULE-STATUS',
            'suppression-update' => 'HTTP 204; SEQUENCE increased; ATTENDEE without SCHEDULE-STATUS',
            'stale-tool' => 'Conflict before dispatch; data and ETag intact',
            'stale-dav' => 'HTTP 412; If-Match refused; data and ETag intact',
            'move' => 'HTTP 201; UID at destination; sync-token of both calendars increased',
            'overwrite' => 'HTTP 412; Overwrite F; two objects intact',
            'delete' => 'HTTP 204; deleted=true; UID out of read scope',
            'invitations' => 'HTTP 204; SCHEDULE-STATUS 1.2; copy and iTIP REQUEST confirmed',
            'transfer' => 'HTTP 201/204; UID transferred and deleted in shared calendar',
            'acl' => 'HTTP 403/404; nothing written',
            'cleanup' => 'HTTP 204; resources of this run removed; session restored',
            'cleanup-empty' => 'No resources created; session restored',
        };
        return sprintf($format, ...$values);
    }

    public static function checkLabel(string $check): string {
        return match ($check) {
            'shared-owner' => Translator::t('shared calendar of another owner'),
            'fresh-uri' => Translator::t('new URI'),
            'mkcalendar-status' => Translator::t('MKCALENDAR 201'),
            'created-owner' => Translator::t('owner of created calendar'),
            'created-etag-sync' => Translator::t('ETag and sync-token after create'),
            'edited-summary-sequence' => Translator::t('SUMMARY and SEQUENCE after update'),
            'tool-conflict-unchanged' => Translator::t('object intact after tool conflict'),
            'dav-conflict-unchanged' => Translator::t('object intact after If-Match'),
            'move-presence-sync' => Translator::t('source absent, target present and sync-token in both calendars'),
            'overwrite-unchanged' => Translator::t('source and target intact after Overwrite F'),
            'deleted-object' => Translator::t('object in trash and out of read scope'),
            'internal-status' => Translator::t('SCHEDULE-STATUS 1.2 on internal attendee'),
            'internal-copy' => Translator::t('copy in attendee calendar'),
            'internal-inbox' => Translator::t('iTIP REQUEST in CalDAV inbox'),
            'acl-unchanged' => Translator::t('ACL refused without writing'),
            'reserved-uri' => Translator::t('URI reserved in this run'),
            'cleanup-identity' => Translator::t('calendar identity before cleanup'),
            'trashed-calendar' => Translator::t('calendar in trash after DELETE'),
            'live-object' => Translator::t('live object by UID'),
            'live-sync' => Translator::t('live sync-token'),
            'attendee-preserved' => Translator::t('ATTENDEE preserved'),
            'suppressed' => Translator::t('scheduling suppression'),
            'refusal-status' => Translator::t('HTTP status of refusal'),
            'refusal' => Translator::t('refused operation'),
            'participant-cleanup' => Translator::t('participant cleanup'),
            default => $check,
        };
    }

    /** @return string human-readable assertion that failed, without backend event content */
    public static function assertion(string $check): string {
        return Translator::t('Unconfirmed effect: %s.', [self::checkLabel($check)]);
    }

    /** @return string one report line; details are generated identifiers or safe errors only */
    public static function line(array $step): string {
        return $step['status'] . ' ' . $step['step'] . ': ' . $step['detail'];
    }
}
