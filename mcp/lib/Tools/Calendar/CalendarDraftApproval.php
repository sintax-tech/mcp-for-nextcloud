<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Conversational approval for every public Calendar write: without confirm=true the call only returns the plan.
 * Nothing is stored; the server cannot prove the user said yes, it only refuses to write before the AI asked.
 */
final class CalendarDraftApproval {
    public function __construct(private CalendarWriteGate $gate, private Scheduling $scheduling, private EventBuilder $builder, private SharedGuard $sharedGuard) {}

    /**
     * @param CalendarWriteTool $tool write handler
     * @param array<string, mixed> $arguments MCP arguments, possibly with confirm and confirm_shared
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} the plan, or the write result after confirm=true
     * @throws CalendarException when the write is refused (shared calendar not acknowledged, ETag changed, ACL)
     */
    public function call(CalendarWriteTool $tool, array $arguments, string $userId): array {
        $confirm = ($arguments['confirm'] ?? false) === true;
        // SharedGuard is shown in the draft; the approved call must explicitly acknowledge it.
        $prepared = $tool->prepare(array_replace($arguments, ['confirm_shared' => true]), $userId);
        if (is_array($prepared)) { throw new \RuntimeException('Calendar write preparation failed'); }
        $name = $tool->definition()['name'];
        $shared = [];
        foreach (array_filter([$prepared->source, $prepared->target]) as $calendar) {
            if (($notice = $this->sharedGuard->confirm($calendar, $userId, [])) !== null) {
                $shared[] = json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $plan = $this->plan($name, $prepared, $arguments) + ['shared' => $shared];
        if (!$confirm) {
            return ToolSchema::result(['requiresConfirmation' => true, 'message' => CalendarMessages::APPROVAL_PROMPT] + $plan);
        }
        if ($shared !== [] && ($arguments['confirm_shared'] ?? false) !== true) { throw CalendarException::blocked(CalendarMessages::APPROVAL_SHARED); }
        // Every confirmed call is prepared again: grants, ACL, ownership, ETag (when sent) and If-Match are rechecked at dispatch time.
        $result = $prepared->dispatch();
        return $prepared->target === null ? $result : ToolSchema::result($result);
    }

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
            'recoverable' => $deleting, 'consequence' => $deleting ? CalendarMessages::PREVIEW_TRASH : null,
            'scheduling' => ['requested' => $notify, 'imipEnabled' => $this->scheduling->imipEnabled(), 'invitationsVerified' => $this->gate->invitationsVerified(), 'participantsNotified' => false,
                'message' => $moving ? CalendarMessages::PARTICIPANTS_NOT_NOTIFIED : ($notify ? ($deleting ? CalendarMessages::PREVIEW_CANCEL : CalendarMessages::PREVIEW_INVITATIONS) : CalendarMessages::PREVIEW_SUPPRESSED),
                'proofScope' => CalendarMessages::PREVIEW_INVITATION_PROOF]];
    }

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
