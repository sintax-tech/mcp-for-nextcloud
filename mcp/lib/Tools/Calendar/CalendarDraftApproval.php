<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * The plan of every public Calendar write, and the execution of the confirmed one.
 *
 * The registry calls {@see self::preview()} whenever a write arrives without `confirm: true` and
 * {@see self::execute()} when it arrives with it, so the confirmation is checked in exactly one place and
 * nowhere else. Nothing is stored: the server cannot prove the user said yes, it only refuses to write
 * before the AI asked. The ETag the plan returns is optional, and when it is sent the confirmed call is
 * refused when the event changed in between.
 */
final class CalendarDraftApproval {
    public function __construct(private Scheduling $scheduling, private EventBuilder $builder, private SharedGuard $sharedGuard) {}

    /**
     * The plan of a write: what the event looks like now, what it would look like after, and what sending it
     * would mean for the participants. Prepared but never dispatched, so no DAV call is written.
     *
     * @param CalendarWriteTool $tool write handler
     * @param array<string, mixed> $arguments MCP arguments, without confirm
     * @param string $userId authenticated UID
     * @return array<string, mixed> the plan, as the structured content of a non-error result
     * @throws CalendarException when the write would be refused (ACL, calendar not found)
     */
    public function preview(CalendarWriteTool $tool, array $arguments, string $userId): array {
        [$prepared, $shared] = $this->prepare($tool, $arguments, $userId);
        return ['requiresConfirmation' => true, 'message' => CalendarMessages::approvalPrompt()]
            + $this->plan($tool->definition()['name'], $prepared, $arguments)
            + ['shared' => $shared];
    }

    /**
     * The confirmed write. Prepared again here: grants, ACL, ownership, ETag (when sent) and If-Match are
     * rechecked at dispatch time, so what runs is what the user approved rather than what it looked like then.
     *
     * @param CalendarWriteTool $tool write handler
     * @param array<string, mixed> $arguments MCP arguments, with confirm
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} the write result
     * @throws CalendarException when the write is refused (shared calendar not acknowledged, ETag changed, ACL)
     */
    public function execute(CalendarWriteTool $tool, array $arguments, string $userId): array {
        [$prepared, $shared] = $this->prepare($tool, $arguments, $userId);
        if ($shared !== [] && ($arguments['confirm_shared'] ?? false) !== true) { throw CalendarException::blocked(CalendarMessages::approvalShared()); }
        $result = $prepared->dispatch();
        return $prepared->target === null ? $result : ToolSchema::result($result);
    }

    /**
     * Prepares the write and reads who it would reach.
     *
     * The plan is built with confirm_shared forced on, so the shared calendars appear in it; the confirmed
     * call still has to acknowledge them with the argument the user sees in the schema.
     *
     * @param CalendarWriteTool $tool write handler
     * @param array<string, mixed> $arguments MCP arguments
     * @param string $userId authenticated UID
     * @return array{0: PreparedCalendarWrite, 1: list<array<string, mixed>>} the prepared write and the shared notices
     * @throws CalendarException when the write would be refused
     */
    private function prepare(CalendarWriteTool $tool, array $arguments, string $userId): array {
        $prepared = $tool->prepare(array_replace($arguments, ['confirm_shared' => true]), $userId);
        if (is_array($prepared)) { throw new \RuntimeException('Calendar write preparation failed'); }
        $shared = [];
        foreach (array_filter([$prepared->source, $prepared->target]) as $calendar) {
            if (($notice = $this->sharedGuard->confirm($calendar, $userId, [])) !== null) {
                $shared[] = json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return [$prepared, $shared];
    }

    /**
     * Builds the review payload from the prepared event change and its notification consequences.
     *
     * @param string $name registered Calendar write tool name
     * @param PreparedCalendarWrite $prepared proposed source, target, and before/after event data
     * @param array<string, mixed> $arguments validated write arguments used to describe scheduling
     * @return array{action:string, calendar:array{id:int, uri:string, name:string, ownerId:string, ownerPrincipal:string, writable:bool, path:string}, destination:array{id:int, uri:string, name:string, ownerId:string, ownerPrincipal:string, writable:bool, path:string}|null, uid:string|null, etag:string|null, before:array{summary:string, location:string, description:string, start:string, end:string, allDay:bool, timeZone:string|null, attendees:list<string>, organizer:string|null, recurring:bool}|null, after:array{summary:string, location:string, description:string, start:string, end:string, allDay:bool, timeZone:string|null, attendees:list<string>, organizer:string|null, recurring:bool}|null, participants:array{current:list<string>, proposed:list<string>, added:list<string>, removed:list<string>}, recoverable:bool, consequence:string|null, scheduling:array{requested:bool, imipEnabled:bool, participantsNotified:bool, message:string, proofScope:string}} review data shown before the user approves the write
     * @throws CalendarException when event data has no master VEVENT
     */
    private function plan(string $name, PreparedCalendarWrite $prepared, array $arguments): array {
        $before = $this->event($prepared->before?->vcalendar);
        $after = $this->event($prepared->after);
        $old = $before['attendees'] ?? [];
        $new = $after['attendees'] ?? [];
        $notify = ($arguments['send_invitations'] ?? false) === true;
        $moving = $prepared->target !== null;
        $deleting = $name === 'calendar_delete_event';
        return ['action' => $name, 'calendar' => (array)$prepared->source, 'destination' => $prepared->target === null ? null : (array)$prepared->target,
            'uid' => $prepared->before === null ? null : (string)$prepared->before->master()?->UID,
            'etag' => $prepared->before?->etag, 'before' => $before, 'after' => $after,
            'participants' => ['current' => $old, 'proposed' => $new, 'added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))],
            'recoverable' => $deleting, 'consequence' => $deleting ? CalendarMessages::previewTrash() : null,
            'scheduling' => ['requested' => $notify, 'imipEnabled' => $this->scheduling->imipEnabled(), 'participantsNotified' => false,
                'message' => $moving ? CalendarMessages::participantsNotNotified() : ($notify ? ($deleting ? CalendarMessages::previewCancel() : CalendarMessages::previewInvitations()) : CalendarMessages::previewSuppressed()),
                'proofScope' => CalendarMessages::previewInvitationProof()]];
    }

    /**
     * Extracts the master event fields and all attendees needed to describe a proposed change.
     *
     * @param VCalendar|null $calendar event calendar, or null when the write creates a new event
     * @return array{summary:string, location:string, description:string, start:string, end:string, allDay:bool, timeZone:string|null, attendees:list<string>, organizer:string|null, recurring:bool}|null event details for the review payload
     * @throws CalendarException when a calendar has no master VEVENT
     */
    private function event(?VCalendar $calendar): ?array {
        if ($calendar === null) { return null; }
        $stored = new StoredEvent(0, '', '', $calendar);
        $master = $stored->master();
        if ($master === null) { throw CalendarException::notFound(); }
        $timing = $this->builder->timing($master);
        $attendees = [];
        // The series preview includes guests on overrides, not just the master.
        foreach ($calendar->select('VEVENT') as $event) {
            foreach ($event->select('ATTENDEE') as $attendee) { $attendees[] = strtolower((string)$attendee); }
        }
        $attendees = array_values(array_unique($attendees)); sort($attendees);
        return ['summary' => (string)$master->SUMMARY, 'location' => (string)$master->LOCATION, 'description' => (string)$master->DESCRIPTION,
            'start' => $timing['start']->format($timing['allDay'] ? 'Y-m-d' : \DateTimeInterface::ATOM), 'end' => $timing['end']->format($timing['allDay'] ? 'Y-m-d' : \DateTimeInterface::ATOM),
            'allDay' => $timing['allDay'], 'timeZone' => $timing['timeZone']?->getName(), 'attendees' => $attendees,
            'organizer' => isset($master->ORGANIZER) ? (string)$master->ORGANIZER : null, 'recurring' => $stored->recurring()];
    }

}
