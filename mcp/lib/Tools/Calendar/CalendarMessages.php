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
    public const TOOL_CREATE_EVENT = 'Creates a simple event (no recurrence) in a writable calendar. Attendees are Nextcloud account IDs; call users_search first if you are not sure of someone\'s ID.';
    public const TOOL_UPDATE_EVENT = 'Updates title, location, description, dates, or attendees (account IDs; call users_search first if unsure) of an event. Recurrent series dates cannot be changed.';
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
    public const PROP_ATTENDEES = 'Nextcloud account IDs (not emails); if you are not sure of someone\'s ID, call users_search first. Replaces the attendee list.';
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

    /** @return string translated conflict message for an event changed by another person */
    public static function conflictEtag(): string {
        return Translator::t('the event was changed by another person (etag diverged).');
    }

    /** @return string translated conflict message for a UID already present in the destination */
    public static function conflictUid(): string {
        return Translator::t('an event with the same UID already exists in the destination calendar.');
    }

    /** @return string translated conflict message for a duplicate event name */
    public static function conflictName(): string {
        return Translator::t('an event with the same name already exists in the destination calendar.');
    }

    /** @return string translated conflict message when source and destination are the same calendar */
    public static function conflictSameCalendar(): string {
        return Translator::t('source and destination are the same calendar.');
    }

    /** @return string translated guidance to use transfer when calendar owners differ */
    public static function moveNeedsTransfer(): string {
        return Translator::t('The calendars have different owners; use calendar_transfer_event.');
    }

    /** @return string translated guidance to use move when calendar owners match */
    public static function transferNeedsMove(): string {
        return Translator::t('The calendars have the same owner; use calendar_move_event.');
    }

    /** @return string translated refusal explaining why attendee events cannot be transferred */
    public static function transferWithAttendees(): string {
        return Translator::t('Cannot transfer an event with attendees to someone else\'s calendar: you remain the organizer and Nextcloud does not notify anyone in this operation. Move the event within your calendars or remove attendees first.');
    }

    /** @return string translated message for event data refused by Nextcloud */
    public static function dataRefused(): string {
        return Translator::t('Nextcloud refused the event data.');
    }

    /** @return string translated message for an event whose data cannot be changed */
    public static function eventUnreadable(): string {
        return Translator::t('The event has invalid data and cannot be changed.');
    }

    /** @return string translated retry message when a move cannot be confirmed */
    public static function moveNotConfirmed(): string {
        return Translator::t('Could not move the event; try again.');
    }

    /** @return string translated refusal when deletion would not be recoverable */
    public static function trashDisabled(): string {
        return Translator::t('Deletion blocked: the calendar trash bin is disabled and the event would not be recoverable.');
    }

    /** @return string translated refusal for changing dates in a recurring series */
    public static function recurringTiming(): string {
        return Translator::t('changing dates of a recurring series is not supported.');
    }

    /** @return string translated refusal for changing attendees in a recurring series */
    public static function recurringAttendees(): string {
        return Translator::t('attendees cannot be changed in a recurring series.');
    }

    /** @return string translated refusal when the user is not the event organizer */
    public static function notOrganizer(): string {
        return Translator::t('only the event organizer can change attendees.');
    }

    /** @return string translated validation message for an invalid attendee list */
    public static function attendeesInvalid(): string {
        return Translator::t('Provide 1 to 50 attendees, without duplicates.');
    }

    /**
     * @param string $uid attendee account id that could not be resolved to an email address
     * @return string translated message naming the unresolved attendee
     */
    public static function attendeeNotFound(string $uid): string {
        return Translator::t('attendee \'%s\' not found or has no email address', [$uid]);
    }

    /** @return string translated message when the organizer has no email address */
    public static function organizerWithoutEmail(): string {
        return Translator::t('your account has no email address, so the event cannot have attendees.');
    }

    /** @return string translated message when a calendar or event is not visible */
    public static function notFound(): string {
        return Translator::t('Calendar or event not found.');
    }

    /** @return string translated message when the user cannot change a calendar or event */
    public static function forbidden(): string {
        return Translator::t('No permission to change this calendar or event.');
    }

    /** @return string translated validation message when a range end is not after its start */
    public static function rangeEndBeforeStart(): string {
        return Translator::t('Invalid range: end must be after start.');
    }

    /** @return string translated validation message when the range start is not before its end */
    public static function rangeFromAfterTo(): string {
        return Translator::t('Invalid range: from must be before to.');
    }

    /**
     * @param string $arg name of the date argument that failed validation
     * @return string translated validation message naming the invalid argument
     */
    public static function invalidIsoDate(string $arg): string {
        return Translator::t('Invalid ISO date in %s.', [$arg]);
    }

    /**
     * @param string $arg name of the all-day date argument that failed validation
     * @return string translated validation message naming the argument that needs YYYY-MM-DD
     */
    public static function invalidAllDayFormat(string $arg): string {
        return Translator::t('Use YYYY-MM-DD format in %s for all-day event.', [$arg]);
    }

    /** @return string translated validation message for an unknown time zone */
    public static function invalidTimeZone(): string {
        return Translator::t('Invalid time zone in timeZone.');
    }

    /** @return string translated validation message when the requested calendar window exceeds its limit */
    public static function windowTooWide(): string {
        return Translator::t('Calendar window exceeds the limit of 366 days.');
    }

    /** @return string translated message when series expansion reaches its occurrence limit */
    public static function occurrenceLimit(): string {
        return Translator::t('Limit of 500 occurrences per series reached; reduce the calendar window.');
    }

    /** @return string translated message when calendar occurrence expansion reaches its limit */
    public static function expansionLimit(): string {
        return Translator::t('Occurrence expansion limit reached; reduce the calendar window.');
    }

    /** @return string translated validation message when an update contains no changes */
    public static function noFieldGiven(): string {
        return Translator::t('Provide at least one field to change.');
    }

    /** @return string translated validation message when changing allDay without supplying dates */
    public static function allDayNeedsDates(): string {
        return Translator::t('Provide start and end when changing allDay.');
    }

    /**
     * @param string $calendar name of the shared calendar being changed
     * @param string $owner display name or account id of its owner
     * @return string translated confirmation prompt describing the shared-calendar change
     */
    public static function sharedConfirmation(string $calendar, string $owner): string {
        return Translator::t('The calendar \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.', [$calendar, $owner]);
    }

    // ---- Scheduling warnings shown in the plan ----

    /**
     * @param string $summary title of the overlapping event
     * @param string $when its time, already formatted for the person
     * @return string translated collision warning
     */
    public static function collisionWith(string $summary, string $when): string {
        return Translator::t('Overlaps %s (%s)', [$summary, $when]);
    }

    /**
     * @param string $when time of the overlapping appointment, already formatted
     * @return string translated collision warning that does not reveal the appointment
     */
    public static function collisionBusy(string $when): string {
        return Translator::t('Overlaps a busy appointment (%s)', [$when]);
    }

    /**
     * @param int $more how many overlaps are not listed
     * @return string translated remainder of the collision list
     */
    public static function collisionMore(int $more): string {
        return Translator::t('+ %d more', [$more]);
    }

    /**
     * @param string $name display name of the busy participant
     * @return string translated busy warning
     */
    public static function attendeeBusy(string $name): string {
        return Translator::t('%s is busy at this time.', [$name]);
    }

    /**
     * @param string $name display name of the participant whose agenda could not be read
     * @return string translated warning
     */
    public static function attendeeUnverifiable(string $name): string {
        return Translator::t('Could not check the availability of %s.', [$name]);
    }

    /** @return string translated warning for a failed availability lookup */
    public static function availabilityFailed(): string {
        return Translator::t('Could not check the participants\' availability.');
    }

    /**
     * @param string $calendar name of the calendar the event would go to
     * @param string $names display names of the participants
     * @param string|null $suggested name of the only shared candidate, if any
     * @param bool $ask whether several candidates exist and the user has to choose
     * @return string translated warning, with the suggestion or the instruction to ask
     */
    public static function calendarNotShared(string $calendar, string $names, ?string $suggested, bool $ask): string {
        $text = Translator::t('The calendar %s is not shared with %s.', [$calendar, $names]);
        if ($suggested !== null) {
            return $text . ' ' . Translator::t('Suggested: %s.', [$suggested]);
        }
        return $ask ? $text . ' ' . Translator::t('Ask the user which calendar to use.') : $text;
    }

    /** @return string translated note that moving an event does not notify its attendees */
    public static function participantsNotNotified(): string {
        return Translator::t('Event attendees are not notified, just like when moving in the Calendar app.');
    }

    /** @return string translated scheduling status when server-side email sending is disabled */
    public static function imipDisabled(): string {
        return Translator::t('server has email invitation sending disabled');
    }

    /** @return string translated scheduling status when an invitation is handed to Nextcloud */
    public static function schedulingHandedOver(): string {
        return Translator::t('invitation delivered to Nextcloud scheduling');
    }

    /** @return string translated scheduling status when no invitation is sent */
    public static function schedulingSuppressed(): string {
        return Translator::t('no invitations were scheduled');
    }

    /**
     * @param int $limit maximum event size accepted by the server, in bytes
     * @return string translated message reporting the event size limit
     */
    public static function eventTooLarge(int $limit): string {
        return Translator::t('The event exceeds the limit of %d bytes accepted by the server.', [$limit]);
    }

    /**
     * @param string $code iTIP schedule status code
     * @return string translated explanation of the delivery status
     */
    public static function scheduleStatus(string $code): string {
        return match (true) {
            str_starts_with($code, '1.1') => Translator::t('delivered to Nextcloud email sending; receipt is not confirmed'),
            str_starts_with($code, '1.2') => Translator::t('delivered to the internal attendee calendar'),
            str_starts_with($code, '1.0') => Translator::t('change without relevance; nothing sent'),
            str_starts_with($code, '3'), str_starts_with($code, '5') => Translator::t('delivery failure (%s)', [$code]),
            default => Translator::t('no delivery record'),
        };
    }

    /**
     * @param string $label human-readable name of the path segment
     * @return string translated validation message for an invalid identifier
     */
    public static function invalidPathSegment(string $label): string {
        return Translator::t('Invalid %s identifier.', [$label]);
    }

    /** @return string translated instruction to obtain approval before repeating a write */
    public static function approvalPrompt(): string {
        return Translator::t('Show this draft to the user and await explicit approval. Only then repeat the same arguments with confirm: true (and the etag of this plan, if present).');
    }

    /** @return string translated warning that a shared-calendar write needs separate confirmation */
    public static function approvalShared(): string {
        return Translator::t('The plan changes a shared calendar belonging to someone else. Execution also requires confirm_shared: true.');
    }

    /** @return string translated preview note that this write schedules no invitations or cancellations */
    public static function previewSuppressed(): string {
        return Translator::t('No invitations or cancellations will be scheduled in this change.');
    }

    /** @return string translated preview note about invitations that may follow approval */
    public static function previewInvitations(): string {
        return Translator::t('After approval, Nextcloud may schedule invitations, updates or CANCEL for removed attendees. Sending and delivery are not guaranteed.');
    }

    /** @return string translated preview note about deletion and possible attendee cancellations */
    public static function previewCancel(): string {
        return Translator::t('After approval, the event will go to the trash bin and Nextcloud may schedule CANCEL to attendees. Sending and delivery are not guaranteed.');
    }

    /** @return string translated preview note that deletion moves an event to recoverable trash */
    public static function previewTrash(): string {
        return Translator::t('After approval, the event will go to the recoverable Calendar trash bin.');
    }

    /** @return string translated note describing the limits of server-side invitation proof */
    public static function previewInvitationProof(): string {
        return Translator::t('Invitations are handed to the CalDAV scheduling of this server; receipt of external email is not proved.');
    }
}
