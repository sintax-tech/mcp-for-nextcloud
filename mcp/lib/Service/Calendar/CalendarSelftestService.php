<?php
declare(strict_types=1);
namespace OCA\Mcp\Service\Calendar;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarWriteGate;
use OCA\Mcp\Tools\Calendar\Handler\CreateEvent;
use OCA\Mcp\Tools\Calendar\Handler\UpdateEvent;
use OCA\Mcp\Tools\Calendar\Handler\MoveEvent;
use OCA\Mcp\Tools\Calendar\Handler\DeleteEvent;
use OCA\Mcp\Tools\Calendar\Handler\TransferEvent;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** Runs real handlers and DAV, then records evidence only after recoverable cleanup passed. */
final class CalendarSelftestService {
    /** All collaborators are ports or ordinary tool handlers; nothing writes via the backend. */
    public function __construct(
        private CalendarDav $dispatcher,
        private CreateEvent $createEvent,
        private UpdateEvent $updateEvent,
        private MoveEvent $moveEvent,
        private DeleteEvent $deleteEvent,
        private TransferEvent $transferEvent,
        private CalendarWriteGate $gate,
        private CalendarAccess $access,
        private CalendarStore $store,
        private ITimeFactory $time,
        private IUserManager $users,
        private IUserSession $session,
        private IAppManager $apps,
        private TrashPolicy $trash,
        private CalendarSelftestReader $reader,
    ) {}

    /**
     * @param string $uid internal organizer account; never obtained from MCP arguments
     * @param array{attendee-uid?:string, shared-calendar?:string, acl-probe-user?:string, no-enable?:bool} $options CLI options
     * @param callable(array{step:string,status:string,detail:string}):void $report safe per-step report
     * @return bool whether every requested check and cleanup succeeded
     */
    public function run(string $uid, array $options, callable $report): bool {
        $this->gate->revoke();
        $previous = $this->session->getUser();
        $owned = [];
        $external = [];
        $invitation = null;
        $operations = ['create', 'edit', 'move', 'delete'];
        $passed = false;
        $invitations = false;
        $step = 'preflight';
        try {
            $user = $this->account($uid);
            $this->require($this->trash->recoverable(), CalendarSelftestMessages::TRASH_REQUIRED);
            $attendee = ($options['attendee-uid'] ?? '') === '' ? null : $this->otherAccount($options['attendee-uid'], $uid);
            $probe = ($options['acl-probe-user'] ?? '') === '' ? null : $this->otherAccount($options['acl-probe-user'], $uid);
            $this->session->setVolatileActiveUser($user);
            $shared = ($options['shared-calendar'] ?? '') === '' ? null : $this->access->resolveWritable($uid, $options['shared-calendar']);
            if ($shared !== null) {
                $this->require($shared->ownerId !== $uid, CalendarSelftestMessages::assertion('shared-owner'));
            }
            $this->ok($report, $step, $uid);

            $step = 'calendars';
            $prefix = 'mcp-selftest-' . $this->time->now()->format('YmdHis') . '-' . bin2hex(random_bytes(8));
            foreach (['a', 'b'] as $suffix) {
                $uri = $prefix . '-' . $suffix;
                foreach ($this->access->visible($uid) as $visible) {
                    $this->require($visible->uri !== $uri, CalendarSelftestMessages::assertion('fresh-uri'));
                }
                // Reserve only an absent, generated name. If MKCALENDAR mutates and then throws,
                // finally still examines this exact name rather than leaking a test calendar.
                $owned[$uri] = null;
                $result = $this->dispatcher->mkcalendar($uid, $uri, CalendarSelftestMessages::EVENT);
                $this->require($result->status === 201, CalendarSelftestMessages::assertion('mkcalendar-status'));
                $owned[$uri] = $this->access->resolveWritable($uid, $this->path($uid, $uri));
                $this->require($owned[$uri]->ownerId === $uid, CalendarSelftestMessages::assertion('created-owner'));
            }
            [$a, $b] = array_values($owned);
            $this->ok($report, $step, CalendarSelftestMessages::detail('calendars', $a->path, $b->path));

            $step = 'create';
            $token = $this->token($a);
            $created = $this->create($uid, $a);
            $row = $this->live($a, $created['uid']);
            $this->require($row['etag'] === $created['etag'] && $this->token($a) > $token, CalendarSelftestMessages::assertion('created-etag-sync'));
            $this->ok($report, $step, CalendarSelftestMessages::detail('create', $created['uid'], $this->token($a)));

            $step = 'suppression-create';
            $suppressed = $this->parse($row['data']);
            $suppressedUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
            $suppressedUri = $suppressedUid . '.ics';
            $suppressed->VEVENT->UID = $suppressedUid;
            $suppressed->VEVENT->add('ORGANIZER', 'mailto:' . $user->getEMailAddress());
            $suppressed->VEVENT->add('ATTENDEE', 'mailto:mcp-selftest@example.invalid', ['RSVP' => 'TRUE']);
            $result = $this->dispatcher->put($uid, $a->uri, $suppressedUri, $suppressed->serialize(), false);
            $suppressedRow = $this->live($a, $suppressedUid);
            $this->assertSuppressed($suppressedRow);
            $this->ok($report, $step, CalendarSelftestMessages::detail('suppression-create', $result->status));

            $step = 'suppression-update';
            $oldEtag = $suppressedRow['etag'];
            $oldSequence = (int)$this->parse($suppressedRow['data'])->VEVENT->SEQUENCE->getValue();
            $this->tool($this->updateEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'summary' => CalendarSelftestMessages::EVENT_EDITED, 'send_invitations' => false], $uid));
            $edited = $this->live($a, $suppressedUid);
            $event = $this->parse($edited['data'])->VEVENT;
            $this->require((string)$event->SUMMARY === CalendarSelftestMessages::EVENT_EDITED && (int)(string)$event->SEQUENCE > $oldSequence, CalendarSelftestMessages::assertion('edited-summary-sequence'));
            $this->assertSuppressed($edited);
            $this->ok($report, $step, CalendarSelftestMessages::detail('suppression-update'));

            $step = 'stale-tool';
            $this->expectRefusal(fn () => $this->updateEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'summary' => CalendarSelftestMessages::EVENT, 'etag' => $oldEtag], $uid));
            $this->require($this->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('tool-conflict-unchanged'));
            $this->ok($report, $step, CalendarSelftestMessages::detail('stale-tool'));

            $step = 'stale-dav';
            $this->expectRefusal(fn () => $this->dispatcher->update($uid, $a->uri, $suppressedUri, $oldEtag, $suppressedRow['data'], false), [412]);
            $this->require($this->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('dav-conflict-unchanged'));
            $this->ok($report, $step, CalendarSelftestMessages::detail('stale-dav'));

            $step = 'move';
            $tokens = [$this->token($a), $this->token($b)];
            $this->tool($this->moveEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'targetCalendar' => $b->path], $uid));
            $moved = $this->live($b, $suppressedUid);
            $this->require($this->store->objectByUid($a->id, $suppressedUid) === null && $this->token($a) > $tokens[0] && $this->token($b) > $tokens[1], CalendarSelftestMessages::assertion('move-presence-sync'));
            $this->assertSuppressed($moved);
            $this->ok($report, $step, CalendarSelftestMessages::detail('move'));

            $step = 'overwrite';
            $collision = $this->parse($row['data']);
            $collisionUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
            $collision->VEVENT->UID = $collisionUid;
            $this->dispatcher->put($uid, $b->uri, $row['uri'], $collision->serialize(), false);
            $targetBefore = $this->live($b, $collisionUid);
            $this->expectRefusal(fn () => $this->dispatcher->move($uid, $a->uri, $row['uri'], $b->uri), [412]);
            $this->require($this->live($a, $created['uid']) === $row && $this->live($b, $collisionUid) === $targetBefore, CalendarSelftestMessages::assertion('overwrite-unchanged'));
            $this->ok($report, $step, CalendarSelftestMessages::detail('overwrite'));

            $step = 'delete';
            $this->delete($uid, $b, $suppressedUid);
            $this->require(($this->store->object($b->id, $suppressedUri)['deleted'] ?? false) && $this->store->objectByUid($b->id, $suppressedUid) === null, CalendarSelftestMessages::assertion('deleted-object'));
            $this->assertSuppressed($this->store->object($b->id, $suppressedUri));
            $this->ok($report, $step, CalendarSelftestMessages::detail('delete'));

            $step = 'invitations';
            if ($attendee === null) {
                $this->skip($report, $step);
            } else {
                $invite = $this->create($uid, $a, [$attendee->getUID()]);
                // Known UID before any scheduling; journal survives a partial scheduler failure.
                $invitation = ['calendar' => $a, 'uid' => $invite['uid'], 'attendee' => $attendee];
                $this->tool($this->updateEvent->execute(['calendar' => $a->path, 'uid' => $invite['uid'], 'summary' => CalendarSelftestMessages::EVENT_EDITED, 'send_invitations' => true], $uid));
                $written = $this->parse($this->live($a, $invite['uid'])['data']);
                $statuses = [];
                foreach ($written->VEVENT->select('ATTENDEE') as $guest) {
                    if (strcasecmp((string)$guest, 'mailto:' . $attendee->getEMailAddress()) === 0) { $statuses[] = (string)($guest['SCHEDULE-STATUS'] ?? ''); }
                }
                $this->require(count($statuses) === 1 && str_starts_with($statuses[0], '1.2'), CalendarSelftestMessages::assertion('internal-status'));
                $this->require($this->copies($attendee->getUID(), $invite['uid']) !== [], CalendarSelftestMessages::assertion('internal-copy'));
                $this->require($this->inbox($attendee->getUID(), $invite['uid'], 'REQUEST') !== [], CalendarSelftestMessages::assertion('internal-inbox'));
                $invitations = true;
                $this->ok($report, $step, CalendarSelftestMessages::detail('invitations'));
            }

            $step = 'transfer';
            if ($shared === null) {
                $this->skip($report, $step);
            } else {
                $transfer = $this->create($uid, $a);
                $external[] = ['calendar' => $shared, 'uid' => $transfer['uid']];
                $this->tool($this->transferEvent->execute(['calendar' => $a->path, 'uid' => $transfer['uid'], 'targetCalendar' => $shared->path, 'confirm' => true], $uid));
                $this->live($shared, $transfer['uid']);
                $this->delete($uid, $shared, $transfer['uid']);
                $operations[] = 'transfer';
                $this->ok($report, $step, CalendarSelftestMessages::detail('transfer'));
            }

            $step = 'acl';
            if ($probe === null) {
                $this->skip($report, $step);
            } else {
                $probeUri = 'mcp-selftest-' . bin2hex(random_bytes(16)) . '.ics';
                $probeIcs = $this->parse($row['data']);
                $probeIcs->VEVENT->UID = substr($probeUri, 0, -4);
                $this->session->setVolatileActiveUser($probe);
                try {
                    $this->expectRefusal(fn () => $this->dispatcher->probeWrite($probe->getUID(), $uid, $a->uri, $probeUri, $probeIcs->serialize()), [403, 404]);
                } finally {
                    $this->session->setVolatileActiveUser($user);
                }
                $this->require($this->store->object($a->id, $probeUri) === null, CalendarSelftestMessages::assertion('acl-unchanged'));
                $this->ok($report, $step, CalendarSelftestMessages::detail('acl'));
            }
            $passed = true;
        } catch (\Throwable $error) {
            $report(['step' => $step, 'status' => 'FALHA', 'detail' => $this->safeError($error)]);
        } finally {
            $clean = true;
            // Every cleanup action is attempted, even after a preceding cleanup failed.
            $attempt = function (string $resource, callable $action) use (&$clean, $report): void {
                try { $action(); } catch (\Throwable $error) {
                    $clean = false;
                    $report(['step' => 'cleanup-resource', 'status' => 'FALHA', 'detail' => $resource . ': ' . $this->safeError($error)]);
                }
            };
            if (isset($user)) {
                $this->session->setVolatileActiveUser($user);
                if ($invitation !== null) {
                    $attempt($invitation['uid'], fn () => $this->cleanupInvitation($user, $invitation));
                }
                foreach ($external as $entry) {
                    $attempt($entry['calendar']->path . $entry['uid'], function () use ($uid, $entry): void {
                        if ($this->store->objectByUid($entry['calendar']->id, $entry['uid']) !== null) { $this->delete($uid, $entry['calendar'], $entry['uid']); }
                    });
                }
                foreach ($owned as $uri => $calendar) {
                    $attempt($this->path($uid, $uri), function () use ($uid, $uri, $calendar, $prefix): void {
                        $this->require(str_starts_with($uri, $prefix . '-'), CalendarSelftestMessages::assertion('reserved-uri'));
                        $current = null;
                        foreach ($this->access->visible($uid) as $visible) { if ($visible->uri === $uri) { $current = $visible; } }
                        if ($current === null) {
                            if ($calendar !== null) { $this->require(($this->reader->calendarState($calendar)['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar')); }
                            return;
                        }
                        $this->require($current->ownerId === $uid && ($calendar === null || $current->id === $calendar->id), CalendarSelftestMessages::assertion('cleanup-identity'));
                        $this->dispatcher->deleteCalendar($uid, $uri);
                        $state = $this->reader->calendarState($current);
                        $this->require(($state['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar'));
                    });
                }
            }
            $this->session->setVolatileActiveUser($previous);
            $report(['step' => 'cleanup', 'status' => $clean ? 'OK' : 'FALHA', 'detail' => $clean ? CalendarSelftestMessages::detail($owned === [] ? 'cleanup-empty' : 'cleanup') : CalendarSelftestMessages::FAILED]);
            $passed = $passed && $clean;
        }
        if ($passed && !($options['no-enable'] ?? false)) {
            $this->gate->recordVerification($operations, $uid, $this->time->now()->format(DATE_ATOM), $invitations);
        }
        $report(['step' => 'gate', 'status' => $passed ? 'OK' : 'FALHA', 'detail' => !$passed ? CalendarSelftestMessages::FAILED : (($options['no-enable'] ?? false) ? CalendarSelftestMessages::PASSED_NO_ENABLE : CalendarSelftestMessages::PASSED)]);
        return $passed;
    }

    /** @return IUser validated internal account with Calendar and e-mail */
    private function account(string $uid): IUser {
        $user = $this->users->get($uid);
        $this->require($user !== null && $user->isEnabled() && filter_var($user->getEMailAddress(), FILTER_VALIDATE_EMAIL) !== false && $this->apps->isEnabledForUser('calendar', $user), CalendarSelftestMessages::INVALID_USER);
        return $user;
    }

    /** @return IUser validated account distinct from organizer */
    private function otherAccount(string $uid, string $organizer): IUser {
        $this->require($uid !== $organizer, CalendarSelftestMessages::INVALID_ATTENDEE);
        return $this->account($uid);
    }

    /** @return array<string,mixed> decoded result from a successful real handler */
    private function tool(array $result): array {
        $this->require(!($result['isError'] ?? false), CalendarSelftestMessages::FAILED);
        $value = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->require(is_array($value) && !($value['requiresConfirmation'] ?? false), CalendarSelftestMessages::FAILED);
        return $value;
    }

    /** @return array<string,mixed> result of the create handler with a generated UUID */
    private function create(string $uid, Calendar $calendar, array $guests = []): array {
        $start = $this->time->now()->modify('+1 day');
        return $this->tool($this->createEvent->execute(['calendar' => $calendar->path, 'summary' => CalendarSelftestMessages::EVENT,
            'start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 hour')->format(DATE_ATOM), 'attendees' => $guests, 'send_invitations' => false], $uid));
    }

    /** @return void deletes a known generated UID through the real handler */
    private function delete(string $uid, Calendar $calendar, string $eventUid, bool $notify = false): void {
        $this->tool($this->deleteEvent->execute(['calendar' => $calendar->path, 'uid' => $eventUid, 'confirm' => true, 'confirm_shared' => true, 'send_invitations' => $notify], $uid));
    }

    /** @return array<string,mixed> live readback by generated UID */
    private function live(Calendar $calendar, string $uid): array {
        $row = $this->store->objectByUid($calendar->id, $uid);
        $this->require($row !== null && !$row['deleted'], CalendarSelftestMessages::assertion('live-object'));
        return $row;
    }

    /** @return int fresh monotonically increasing backend sync token */
    private function token(Calendar $calendar): int {
        $state = $this->reader->calendarState($calendar);
        $this->require($state !== null && ctype_digit($state['syncToken']) && !$state['deleted'], CalendarSelftestMessages::assertion('live-sync'));
        return (int)$state['syncToken'];
    }

    /** @return VCalendar parsed backend data */
    private function parse(string $data): VCalendar {
        $calendar = Reader::read($data);
        $this->require($calendar instanceof VCalendar, CalendarSelftestMessages::FAILED);
        return $calendar;
    }

    /** @return void checks a real attendee survived with no scheduler status */
    private function assertSuppressed(array $row): void {
        $guests = $this->parse($row['data'])->VEVENT->select('ATTENDEE');
        $this->require(count($guests) === 1, CalendarSelftestMessages::assertion('attendee-preserved'));
        foreach ($guests as $guest) {
            $this->require((string)$guest === 'mailto:mcp-selftest@example.invalid' && !isset($guest['SCHEDULE-STATUS']), CalendarSelftestMessages::assertion('suppressed'));
        }
    }

    /** @return void expects a mapped refusal; only explicit HTTP codes prove DAV preconditions */
    private function expectRefusal(callable $action, array $httpCodes = []): void {
        try { $action(); } catch (CalendarException $error) {
            $this->require($httpCodes === [] || in_array($error->getCode(), $httpCodes, true), CalendarSelftestMessages::assertion('refusal-status'));
            return;
        }
        throw new \RuntimeException(CalendarSelftestMessages::assertion('refusal'));
    }

    /** @return list<array{calendar:Calendar,row:array}> participant-owned copies of this generated UID */
    private function copies(string $uid, string $eventUid): array {
        $copies = [];
        foreach ($this->access->visible($uid) as $calendar) {
            if ($calendar->ownerId === $uid && ($row = $this->store->objectByUid($calendar->id, $eventUid)) !== null) { $copies[] = ['calendar' => $calendar, 'row' => $row]; }
        }
        return $copies;
    }

    /** @return list<array{uri:string,data:string,etag:string}> only inbox objects with our UID and optional METHOD */
    private function inbox(string $uid, string $eventUid, ?string $method = null): array {
        $matches = [];
        foreach ($this->reader->schedulingObjects($uid) as $row) {
            $ical = $this->parse($row['data']);
            if ($ical->VEVENT !== null && (string)$ical->VEVENT->UID === $eventUid && ($method === null || (string)$ical->METHOD === $method)) { $matches[] = $row; }
        }
        return $matches;
    }

    /** @return void cancels as organizer then removes only matching participant copies/inbox items */
    private function cleanupInvitation(IUser $organizer, array $entry): void {
        $attendee = $entry['attendee'];
        $uid = $entry['uid'];
        try {
            if ($this->store->objectByUid($entry['calendar']->id, $uid) !== null) { $this->delete($organizer->getUID(), $entry['calendar'], $uid, true); }
        } finally {
            $this->session->setVolatileActiveUser($attendee);
            try {
                foreach ($this->copies($attendee->getUID(), $uid) as $copy) {
                    $this->dispatcher->delete($attendee->getUID(), $copy['calendar']->uri, $copy['row']['uri'], $copy['row']['etag'], false);
                }
                foreach ($this->inbox($attendee->getUID(), $uid) as $item) {
                    // Re-read membership and ETag immediately before DELETE; no name from arguments.
                    foreach ($this->inbox($attendee->getUID(), $uid) as $current) {
                        if ($current['uri'] === $item['uri'] && $current['etag'] === $item['etag']) {
                            $this->dispatcher->delete($attendee->getUID(), 'inbox', $item['uri'], $item['etag'], false);
                        }
                    }
                }
                $this->require($this->copies($attendee->getUID(), $uid) === [] && $this->inbox($attendee->getUID(), $uid) === [], CalendarSelftestMessages::assertion('participant-cleanup'));
            } finally { $this->session->setVolatileActiveUser($organizer); }
        }
    }

    /** @return string path made from generated collection identifiers */
    private function path(string $uid, string $uri): string {
        return '/remote.php/dav/calendars/' . rawurlencode($uid) . '/' . rawurlencode($uri) . '/';
    }

    /** @return void rejects an unproved effect with a safe message */
    private function require(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    /** @return string no raw backend exception, ICS or XML is printed */
    private function safeError(\Throwable $error): string {
        if ($error instanceof CalendarException || $error instanceof CalendarArgumentException) { return $error->getMessage(); }
        if (str_starts_with($error->getMessage(), CalendarSelftestMessages::ASSERTION_PREFIX) || in_array($error->getMessage(), [CalendarSelftestMessages::TRASH_REQUIRED, CalendarSelftestMessages::INVALID_USER, CalendarSelftestMessages::INVALID_ATTENDEE], true)) { return $error->getMessage(); }
        return CalendarSelftestMessages::FAILED . ' (' . $error::class . ')';
    }

    /** @return void emits a successful stage */
    private function ok(callable $report, string $step, string $detail): void {
        $report(['step' => $step, 'status' => 'OK', 'detail' => $detail]);
    }

    /** @return void emits an optional stage without claiming proof */
    private function skip(callable $report, string $step): void {
        $report(['step' => $step, 'status' => 'NOT TESTED', 'detail' => CalendarSelftestMessages::OPTIONAL]);
    }
}
