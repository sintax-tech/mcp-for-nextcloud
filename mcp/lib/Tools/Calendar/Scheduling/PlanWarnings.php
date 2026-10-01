<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Calendar\AttendeeResolver;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\EventBuilder;
use OCA\Mcp\Tools\Calendar\PreparedCalendarWrite;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VEvent;
use Throwable;

/**
 * Gathers the scheduling warnings of a Calendar write plan: collisions in the target calendar, participants
 * who are busy or cannot be checked, and calendars the participants already share.
 *
 * Warnings only inform: they never throw and never block the confirmed call, so a source that fails is
 * left out of the plan rather than breaking it. They name the participants and the user's own events, and
 * never the content of an appointment that is only visible as busy.
 */
final class PlanWarnings {
    /** Write tools whose plan carries warnings. */
    private const TOOLS = ['calendar_create_event', 'calendar_update_event', 'calendar_move_event', 'calendar_transfer_event'];

    /**
     * @param CollisionCheck $collisions overlaps in the target calendar
     * @param AvailabilityCheck $availability free/busy verdict of the participants
     * @param SharedCalendarFinder $sharedCalendars calendars every participant already sees
     * @param AttendeeResolver $attendees resolves the participant UIDs of the arguments
     * @param EventBuilder $builder reads the timing of the proposed event
     * @param IUserManager $users names the owner of a shared calendar that is not the user's
     * @param LoggerInterface|null $logger receives the class of a failure, never its message
     */
    public function __construct(
        private CollisionCheck $collisions,
        private AvailabilityCheck $availability,
        private SharedCalendarFinder $sharedCalendars,
        private AttendeeResolver $attendees,
        private EventBuilder $builder,
        private IUserManager $users,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param string $tool registered write tool name
     * @return bool whether the plan of this tool has a warnings section
     */
    public function applies(string $tool): bool {
        return in_array($tool, self::TOOLS, true);
    }

    /**
     * Builds the warnings of a prepared write.
     *
     * Create is checked in full. Update is checked only when the start, end, all-day flag or the guest list
     * changes. Move and transfer are checked against the destination calendar only, since neither changes the
     * time or the guests.
     *
     * @param string $tool registered write tool name
     * @param PreparedCalendarWrite $prepared proposed write
     * @param array<string, mixed> $arguments tool arguments, as validated
     * @param string $userId acting user
     * @return array{warnings: list<array{type:string, message:string}>, sharedCalendars: list<array{path:string, name:string, owner?:string}>, suggestedCalendar: array{path:string, name:string}|null}
     */
    public function forWrite(string $tool, PreparedCalendarWrite $prepared, array $arguments, string $userId): array {
        $none = ['warnings' => [], 'sharedCalendars' => [], 'suggestedCalendar' => null];
        $master = $prepared->after?->VEVENT ?? null;
        if (!$master instanceof VEvent) {
            return $none;
        }
        $timing = $this->builder->timing($master);
        $isUpdate = $tool === 'calendar_update_event';
        $guests = $this->guests($tool, $master, $arguments, $userId);
        if ($isUpdate && !$this->changed($prepared, $timing, $master)) {
            return $none;
        }
        $zone = $timing['timeZone'] ?? new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $calendar = $prepared->target ?? $prepared->source;
        $warnings = $this->collisionWarnings($calendar, $userId, $timing, $prepared->before?->uri, $zone);
        $shared = [];
        $suggested = null;
        if ($guests !== null && $guests !== []) {
            array_push($warnings, ...$this->availabilityWarnings($userId, $timing, $guests));
            if ($tool === 'calendar_create_event' || array_key_exists('attendees', $arguments)) {
                [$sharing, $shared, $suggested] = $this->sharingWarnings($calendar, $userId, $guests);
                array_push($warnings, ...$sharing);
            }
        }
        return ['warnings' => $warnings, 'sharedCalendars' => $shared, 'suggestedCalendar' => $suggested];
    }

    /**
     * @param string $tool write tool name
     * @param VEvent $master proposed event
     * @param array<string, mixed> $arguments tool arguments
     * @param string $userId acting user
     * @return list<array{uid:string, email:string, displayName:string}>|null participants to check, or null for tools that do not check them
     */
    private function guests(string $tool, VEvent $master, array $arguments, string $userId): ?array {
        if ($tool !== 'calendar_create_event' && $tool !== 'calendar_update_event') {
            return null;
        }
        if ($tool === 'calendar_create_event' || array_key_exists('attendees', $arguments)) {
            try {
                return $this->attendees->resolve($arguments['attendees'] ?? [], $userId);
            } catch (Throwable $e) {
                $this->failed($e);
                return null;
            }
        }
        $organizer = isset($master->ORGANIZER) ? strtolower(preg_replace('/^mailto:/i', '', (string)$master->ORGANIZER) ?? '') : '';
        $guests = [];
        foreach ($master->select('ATTENDEE') as $attendee) {
            $email = preg_replace('/^mailto:/i', '', (string)$attendee) ?? '';
            if ($email === '' || strtolower($email) === $organizer) {
                continue;
            }
            $name = trim((string)($attendee['CN'] ?? ''));
            $guests[] = ['uid' => $email, 'email' => $email, 'displayName' => $name !== '' ? $name : $email];
        }
        return $guests;
    }

    /**
     * @param PreparedCalendarWrite $prepared update being planned
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, allDay: bool, timeZone: DateTimeZone|null} $timing proposed timing
     * @param VEvent $master proposed event
     * @return bool whether the start, end, all-day flag or guest list differ from the stored event
     */
    private function changed(PreparedCalendarWrite $prepared, array $timing, VEvent $master): bool {
        $before = $prepared->before?->master();
        if ($before === null) {
            return true;
        }
        $old = $this->builder->timing($before);
        if ($old['start'] != $timing['start'] || $old['end'] != $timing['end'] || $old['allDay'] !== $timing['allDay']) {
            return true;
        }
        return $this->emails($before) !== $this->emails($master);
    }

    /**
     * @param VEvent $event event to read
     * @return list<string> sorted lower-case guest addresses
     */
    private function emails(VEvent $event): array {
        $emails = array_map(static fn ($a): string => strtolower((string)$a), $event->select('ATTENDEE'));
        sort($emails);
        return $emails;
    }

    /**
     * @param Calendar $calendar calendar the event would live in
     * @param string $userId acting user
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, allDay: bool, timeZone: DateTimeZone|null} $timing proposed timing
     * @param string|null $excludeUri object of the event itself, when it already exists
     * @param DateTimeZone $zone zone the times are written in
     * @return list<array{type:string, message:string}> one warning per overlap, plus the remainder
     */
    private function collisionWarnings(Calendar $calendar, string $userId, array $timing, ?string $excludeUri, DateTimeZone $zone): array {
        try {
            $found = $this->collisions->find($calendar, $userId, $timing['start'], $timing['end'], $timing['allDay'], $excludeUri);
        } catch (Throwable $e) {
            $this->failed($e);
            return [];
        }
        $warnings = [];
        foreach ($found['items'] as $item) {
            $when = $this->when($item['start'], $item['end'], $item['allDay'], $zone);
            $title = $item['summary'] !== null && trim($item['summary']) !== '' ? $item['summary'] : Translator::t('(no title)');
            $message = $item['busyOnly'] ? CalendarMessages::collisionBusy($when) : CalendarMessages::collisionWith($title, $when);
            $warnings[] = ['type' => 'collision', 'message' => $message];
        }
        if ($found['more'] > 0) {
            $warnings[] = ['type' => 'collision', 'message' => CalendarMessages::collisionMore($found['more'])];
        }
        return $warnings;
    }

    /**
     * @param string $userId acting user
     * @param array{start: DateTimeImmutable, end: DateTimeImmutable, allDay: bool, timeZone: DateTimeZone|null} $timing proposed timing
     * @param list<array{uid:string, email:string, displayName:string}> $guests participants
     * @return list<array{type:string, message:string}> busy and unverifiable warnings
     */
    private function availabilityWarnings(string $userId, array $timing, array $guests): array {
        try {
            $result = $this->availability->check($userId, $timing['start'], $timing['end'], $guests);
        } catch (Throwable $e) {
            $this->failed($e);
            $result = ['busy' => [], 'unverifiable' => [], 'failed' => true];
        }
        if ($result['failed']) {
            return [['type' => 'unverifiable', 'message' => CalendarMessages::availabilityFailed()]];
        }
        $warnings = [];
        foreach ($result['busy'] as $busy) {
            $warnings[] = ['type' => 'busy', 'message' => CalendarMessages::attendeeBusy($busy['displayName'])];
        }
        foreach ($result['unverifiable'] as $unknown) {
            $warnings[] = ['type' => 'unverifiable', 'message' => CalendarMessages::attendeeUnverifiable($unknown['displayName'])];
        }
        return $warnings;
    }

    /**
     * @param Calendar $calendar calendar the event would live in
     * @param string $userId acting user
     * @param list<array{uid:string, email:string, displayName:string}> $guests participants
     * @return array{0: list<array{type:string, message:string}>, 1: list<array{path:string, name:string, owner?:string}>, 2: array{path:string, name:string}|null} warnings, candidates and the suggestion
     */
    private function sharingWarnings(Calendar $calendar, string $userId, array $guests): array {
        try {
            $candidates = $this->sharedCalendars->candidates($userId, array_column($guests, 'uid'));
        } catch (Throwable $e) {
            $this->failed($e);
            return [[], [], null];
        }
        foreach ($candidates as $candidate) {
            if ($candidate['path'] === $calendar->path) {
                return [[], [], null];
            }
        }
        // The owner is named only when the calendar is somebody else's; the path stays for the model to repeat the call.
        $list = array_map(function (array $c) use ($userId): array {
            $item = ['path' => $c['path'], 'name' => $c['name']];
            if ($c['ownerId'] !== $userId) {
                $item['owner'] = $this->users->get($c['ownerId'])?->getDisplayName() ?: $c['ownerId'];
            }
            return $item;
        }, $candidates);
        $suggested = count($list) === 1 ? ['path' => $list[0]['path'], 'name' => $list[0]['name']] : null;
        $names = implode(', ', array_column($guests, 'displayName'));
        $message = CalendarMessages::calendarNotShared($calendar->name, $names, $suggested['name'] ?? null, count($list) > 1);
        return [[['type' => 'calendarNotShared', 'message' => $message]], $list, $suggested];
    }

    /**
     * Formats the time of an overlap for the person: weekday, date and the time range in the event's zone;
     * an all-day event shows its civil days.
     *
     * @param string $start ISO 8601 UTC start of the overlap
     * @param string $end ISO 8601 UTC end of the overlap
     * @param bool $allDay whether the overlapping event is an all-day one
     * @param DateTimeZone $zone zone of the event being planned
     * @return string localized time, in the account language
     */
    private function when(string $start, string $end, bool $allDay, DateTimeZone $zone): string {
        $locale = Translator::locale();
        $tz = $allDay ? new DateTimeZone('UTC') : $zone;
        $from = (new DateTimeImmutable($start))->setTimezone($tz);
        $to = (new DateTimeImmutable($end))->setTimezone($tz);
        $weekday = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $tz, null, 'EEE');
        $date = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $tz);
        $time = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::SHORT, $tz);
        $day = static fn (DateTimeImmutable $d): string => rtrim((string)$weekday->format($d), '.') . ', ' . $date->format($d);
        if ($allDay) {
            $last = $to > $from ? $to->modify('-1 day') : $from;
            return $last->format('Y-m-d') <= $from->format('Y-m-d') ? $day($from) : $day($from) . ' – ' . $day($last);
        }
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $day($from) . ', ' . $time->format($from) . '–' . $time->format($to);
        }
        return $day($from) . ', ' . $time->format($from) . ' – ' . $day($to) . ', ' . $time->format($to);
    }

    /**
     * @param Throwable $e failure of a warning source
     * @return void
     */
    private function failed(Throwable $e): void {
        $this->logger?->warning('MCP calendar: scheduling warning skipped', ['app' => 'mcp', 'exception_class' => $e::class]);
    }
}
