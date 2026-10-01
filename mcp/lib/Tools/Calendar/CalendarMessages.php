<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCA\Mcp\L10n\Translator;

/**
 * Every user-facing string of the calendar write path, in one place.
 *
 * Descriptions of tools and parameters are fixed English ASCII constants for the model.
 * Messages returned to the user or agent in tool execution are methods wrapping English
 * literal sources in via Translator.
 */
final class CalendarMessages {
    // ---- Approval and draft messages ----

    public const APPROVAL_PROMPT = 'Show this draft to the user and await explicit approval. Only then repeat the same arguments with confirm: true (and the etag of this plan, if present).';
    public const APPROVAL_SHARED = 'The plan changes a shared calendar belonging to someone else. Execution also requires confirm_shared: true.';
    public const PREVIEW_SUPPRESSED = 'No invitations or cancellations will be scheduled in this change.';
    public const PREVIEW_INVITATIONS = 'After approval, Nextcloud may schedule invitations, updates or CANCEL for removed attendees. Sending and delivery are not guaranteed.';
    public const PREVIEW_CANCEL = 'After approval, the event will go to the trash bin and Nextcloud may schedule CANCEL to attendees. Sending and delivery are not guaranteed.';
    public const PREVIEW_TRASH = 'After approval, the event will go to the recoverable Calendar trash bin.';
    public const PREVIEW_INVITATION_PROOF = 'Server proof covers internal delivery and suppression by the DAV pipeline; it does not prove receipt of external email.';

    // ---- Tool descriptions shown in tools/list (fixed English) ----

    public const TOOL_LIST_CALENDARS = 'Lists calendars visible to the user.';
    public const TOOL_LIST_EVENTS = 'Lists events in a time range (default: next 7 days).';
    public const TOOL_CREATE_EVENT = 'Creates a simple event (no recurrence) in a writable calendar.';
    public const TOOL_UPDATE_EVENT = 'Updates title, location, description, dates, or attendees of an event. Recurrent series dates cannot be changed.';
    public const TOOL_DELETE_EVENT = 'Deletes an event (the entire series), moving it to the calendar trash bin. Requires confirm: true.';
    public const TOOL_MOVE_EVENT = 'Moves an event to another calendar of the same owner, without overwriting.';
    public const TOOL_TRANSFER_EVENT = 'Transfers an event to a calendar owned by another user and shared with write permission. Requires confirm: true. Events with attendees are rejected: you remain the organizer and Nextcloud does not notify anyone in this operation.';

    public const PROP_CALENDAR = 'calendar path, as returned by calendar_list_calendars';
    public const PROP_ALL_DAY = 'all-day event';
    public const EXPANSION_LIMIT = 'Occurrence expansion limit reached; reduce the calendar window.';

    private const PROPERTY_DESCRIPTIONS = [
        'summary' => 'event title',
        'start' => 'start: YYYY-MM-DD (all-day) or ISO with Z/offset',
        'end' => 'exclusive end, in the same format as start',
        'timezone' => 'IANA time zone, e.g. America/Sao_Paulo',
        'location' => 'location',
        'description' => 'description',
        'calendar-optional' => 'calendar path (optional)',
        'from' => 'start ISO date',
        'to' => 'end ISO date',
        'updated-summary' => 'new title',
        'updated-start' => 'new start: YYYY-MM-DD (all-day) or ISO with Z/offset',
        'updated-end' => 'new exclusive end',
        'updated-location' => 'new location; empty to clear',
        'updated-description' => 'new description; empty to clear',
        'source-calendar' => 'source calendar path',
        'target-calendar' => 'destination calendar path',
        'transfer-calendar' => 'destination calendar path, owned by another user',
    ];

    // ---- Fixed property descriptions of the schema helpers ----

    public const PROP_UID = 'event UID';
    public const PROP_ETAG = 'expected etag; if it differs, nothing is changed';
    public const PROP_CONFIRM = 'must be true to confirm the operation';
    public const PROP_ATTENDEES = 'Nextcloud account IDs (not emails). Replaces the attendee list.';
    public const PROP_SEND_INVITATIONS = 'true delivers the invitation to Nextcloud scheduling; email depends on server settings and does not confirm receipt';

    // ---- Path building ----

    /** Object URI segment. */
    public const PATH_OBJECT = 'object';
    /** Calendar URI segment. */
    public const PATH_CALENDAR = 'calendar';
    /** UID segment. */
    public const PATH_USER = 'user';

    // ---- Fragments shared by tool descriptions ----

    public const INVITES_OTHERS = ' This call may send invitations to other people.';
    public const SEND_INVITATIONS_NOTE = ' Attendees are only notified with send_invitations: true, depending on server configuration; sending does not confirm receipt.';
    public const PARTICIPANTS_NOT_NOTIFIED = ' Event attendees are not notified, just like when moving in the Calendar app.';
    public const SHARED_CONFIRMATION_SUFFIX = ' If the resource belongs to someone else, the agent MUST ask the user before sending confirm_shared: true.';
    public const CONFIRM_SHARED_PROPERTY = 'set to true only after the user confirms changing a calendar that belongs to someone else';
    public const DAV_FAILURE = 'Calendar DAV failure';

    // ---- Backward-compatible English constants for messages ----

    public const CONFLICT_ETAG = 'the event was changed by another person (etag diverged).';
    public const CONFLICT_UID = 'an event with the same UID already exists in the destination calendar.';
    public const CONFLICT_NAME = 'an event with the same name already exists in the destination calendar.';
    public const CONFLICT_SAME_CALENDAR = 'source and destination are the same calendar.';
    public const MOVE_NEEDS_TRANSFER = 'The calendars have different owners; use calendar_transfer_event.';
    public const TRANSFER_NEEDS_MOVE = 'The calendars have the same owner; use calendar_move_event.';
    public const DATA_REFUSED = 'Nextcloud refused the event data.';
    public const EVENT_UNREADABLE = 'The event has invalid data and cannot be changed.';
    public const TRASH_DISABLED = 'Deletion blocked: the calendar trash bin is disabled and the event would not be recoverable.';
    public const RECURRING_TIMING = 'changing dates of a recurring series is not supported.';
    public const RECURRING_ATTENDEES = 'attendees cannot be changed in a recurring series.';
    public const NOT_ORGANIZER = 'only the event organizer can change attendees.';
    public const MOVE_NOT_CONFIRMED = 'Could not move the event; try again.';
    public const TRANSFER_WITH_ATTENDEES = 'Cannot transfer an event with attendees to someone else\'s calendar: you remain the organizer and Nextcloud does not notify anyone in this operation. Move the event within your calendars or remove attendees first.';
    public const INVITATIONS_UNVERIFIED = 'sending invitations not yet verified on this server';
    public const ATTENDEES_INVALID = 'Provide 1 to 50 attendees, without duplicates.';
    public const ATTENDEE_NOT_FOUND = 'attendee \'%s\' not found or has no email address';
    public const ORGANIZER_WITHOUT_EMAIL = 'your account has no email address, so the event cannot have attendees.';
    public const NOT_FOUND = 'Calendar or event not found.';
    public const FORBIDDEN = 'No permission to change this calendar or event.';
    public const RANGE_END_BEFORE_START = 'Invalid range: end must be after start.';
    public const RANGE_FROM_AFTER_TO = 'Invalid range: from must be before to.';
    public const INVALID_ISO_DATE = 'Invalid ISO date in %s.';
    public const INVALID_ALL_DAY_FORMAT = 'Use YYYY-MM-DD format in %s for all-day event.';
    public const INVALID_TIME_ZONE = 'Invalid time zone in timeZone.';
    public const WINDOW_TOO_WIDE = 'Calendar window exceeds the limit of 366 days.';
    public const OCCURRENCE_LIMIT = 'Limit of 500 occurrences per series reached; reduce the calendar window.';
    public const NO_FIELD_GIVEN = 'Provide at least one field to change.';
    public const ALL_DAY_NEEDS_DATES = 'Provide start and end when changing allDay.';
    public const SHARED_CONFIRMATION = "The calendar '%s' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.";
    public const IMIP_DISABLED = 'server has email invitation sending disabled';
    public const SCHEDULING_HANDED_OVER = 'invitation delivered to Nextcloud scheduling';
    public const SCHEDULING_SUPPRESSED = 'no invitations were scheduled';

    private function __construct() {
    }

    /** @return string schema property text */
    public static function propertyDescription(string $key): string {
        return self::PROPERTY_DESCRIPTIONS[$key];
    }

    /** @return string safe conflict message */
    public static function conflict(string $reason): string {
        return Translator::t('Conflict: %s', [$reason]);
    }

    public static function conflictEtag(): string {
        return Translator::t('the event was changed by another person (etag diverged).');
    }

    public static function conflictUid(): string {
        return Translator::t('an event with the same UID already exists in the destination calendar.');
    }

    public static function conflictName(): string {
        return Translator::t('an event with the same name already exists in the destination calendar.');
    }

    public static function conflictSameCalendar(): string {
        return Translator::t('source and destination are the same calendar.');
    }

    public static function moveNeedsTransfer(): string {
        return Translator::t('The calendars have different owners; use calendar_transfer_event.');
    }

    public static function transferNeedsMove(): string {
        return Translator::t('The calendars have the same owner; use calendar_move_event.');
    }

    public static function transferWithAttendees(): string {
        return Translator::t('Cannot transfer an event with attendees to someone else\'s calendar: you remain the organizer and Nextcloud does not notify anyone in this operation. Move the event within your calendars or remove attendees first.');
    }

    public static function dataRefused(): string {
        return Translator::t('Nextcloud refused the event data.');
    }

    public static function eventUnreadable(): string {
        return Translator::t('The event has invalid data and cannot be changed.');
    }

    public static function moveNotConfirmed(): string {
        return Translator::t('Could not move the event; try again.');
    }

    public static function trashDisabled(): string {
        return Translator::t('Deletion blocked: the calendar trash bin is disabled and the event would not be recoverable.');
    }

    public static function recurringTiming(): string {
        return Translator::t('changing dates of a recurring series is not supported.');
    }

    public static function recurringAttendees(): string {
        return Translator::t('attendees cannot be changed in a recurring series.');
    }

    public static function notOrganizer(): string {
        return Translator::t('only the event organizer can change attendees.');
    }

    public static function invitationsUnverified(): string {
        return Translator::t('sending invitations not yet verified on this server');
    }

    public static function attendeesInvalid(): string {
        return Translator::t('Provide 1 to 50 attendees, without duplicates.');
    }

    public static function attendeeNotFound(string $uid): string {
        return Translator::t('attendee \'%s\' not found or has no email address', [$uid]);
    }

    public static function organizerWithoutEmail(): string {
        return Translator::t('your account has no email address, so the event cannot have attendees.');
    }

    public static function notFound(): string {
        return Translator::t('Calendar or event not found.');
    }

    public static function forbidden(): string {
        return Translator::t('No permission to change this calendar or event.');
    }

    public static function rangeEndBeforeStart(): string {
        return Translator::t('Invalid range: end must be after start.');
    }

    public static function rangeFromAfterTo(): string {
        return Translator::t('Invalid range: from must be before to.');
    }

    public static function invalidIsoDate(string $arg): string {
        return Translator::t('Invalid ISO date in %s.', [$arg]);
    }

    public static function invalidAllDayFormat(string $arg): string {
        return Translator::t('Use YYYY-MM-DD format in %s for all-day event.', [$arg]);
    }

    public static function invalidTimeZone(): string {
        return Translator::t('Invalid time zone in timeZone.');
    }

    public static function windowTooWide(): string {
        return Translator::t('Calendar window exceeds the limit of 366 days.');
    }

    public static function occurrenceLimit(): string {
        return Translator::t('Limit of 500 occurrences per series reached; reduce the calendar window.');
    }

    public static function expansionLimit(): string {
        return Translator::t('Occurrence expansion limit reached; reduce the calendar window.');
    }

    public static function noFieldGiven(): string {
        return Translator::t('Provide at least one field to change.');
    }

    public static function allDayNeedsDates(): string {
        return Translator::t('Provide start and end when changing allDay.');
    }

    public static function sharedConfirmation(string $calendar, string $owner): string {
        return Translator::t('The calendar \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$calendar, $owner]);
    }

    public static function participantsNotNotified(): string {
        return Translator::t('Event attendees are not notified, just like when moving in the Calendar app.');
    }

    public static function imipDisabled(): string {
        return Translator::t('server has email invitation sending disabled');
    }

    public static function schedulingHandedOver(): string {
        return Translator::t('invitation delivered to Nextcloud scheduling');
    }

    public static function schedulingSuppressed(): string {
        return Translator::t('no invitations were scheduled');
    }

    public static function eventTooLarge(int $limit): string {
        return Translator::t('The event exceeds the limit of %d bytes accepted by the server.', [$limit]);
    }

    public static function scheduleStatus(string $code): string {
        return match (true) {
            str_starts_with($code, '1.1') => Translator::t('delivered to Nextcloud email sending; receipt is not confirmed'),
            str_starts_with($code, '1.2') => Translator::t('delivered to the internal attendee calendar'),
            str_starts_with($code, '1.0') => Translator::t('change without relevance; nothing sent'),
            str_starts_with($code, '3'), str_starts_with($code, '5') => Translator::t('delivery failure (%s)', [$code]),
            default => Translator::t('no delivery record'),
        };
    }

    public static function invalidPathSegment(string $label): string {
        return Translator::t('Invalid %s identifier.', [$label]);
    }

    public static function approvalPrompt(): string {
        return Translator::t('Show this draft to the user and await explicit approval. Only then repeat the same arguments with confirm: true (and the etag of this plan, if present).');
    }

    public static function approvalShared(): string {
        return Translator::t('The plan changes a shared calendar belonging to someone else. Execution also requires confirm_shared: true.');
    }

    public static function previewSuppressed(): string {
        return Translator::t('No invitations or cancellations will be scheduled in this change.');
    }

    public static function previewInvitations(): string {
        return Translator::t('After approval, Nextcloud may schedule invitations, updates or CANCEL for removed attendees. Sending and delivery are not guaranteed.');
    }

    public static function previewCancel(): string {
        return Translator::t('After approval, the event will go to the trash bin and Nextcloud may schedule CANCEL to attendees. Sending and delivery are not guaranteed.');
    }

    public static function previewTrash(): string {
        return Translator::t('After approval, the event will go to the recoverable Calendar trash bin.');
    }

    public static function previewInvitationProof(): string {
        return Translator::t('Server proof covers internal delivery and suppression by the DAV pipeline; it does not prove receipt of external email.');
    }
}
