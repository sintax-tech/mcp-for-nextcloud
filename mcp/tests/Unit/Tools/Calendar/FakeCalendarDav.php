<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\DavResult;
use Throwable;

/**
 * In-memory CalendarDav that records every dispatched request and applies it to a FakeCalendarStore,
 * so the handler tests still assert on the object the write produced.
 *
 * It can also refuse an operation the way the pipeline would: `statusFor` maps an operation to the
 * status the dispatcher should answer with, and `failureFor` to an exception it should throw.
 */
final class FakeCalendarDav implements CalendarDav {
    /** @var list<array{0:string, 1:array<string, mixed>}> dispatched calls: [operation, arguments] */
    public array $calls = [];
    public bool $selftestMode = false;
    public ?\Closure $afterMkcalendar = null;
    public ?\Closure $schedule = null;
    public ?\Closure $deleteInbox = null;
    /** @var array<string, int> operation to HTTP status to answer instead of applying the write */
    public array $statusFor = [];
    /** @var array<string, Throwable> operation to exception to throw instead of applying the write */
    public array $failureFor = [];

    /**
     * @param FakeCalendarStore $store store the writes are applied to, so the read path sees them
     */
    public function __construct(private FakeCalendarStore $store) {}

    /** @inheritDoc */
    public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult {
        return $this->apply('put', [$calendarUri, $objectUri, $ics, $scheduling], function () use ($calendarUri, $objectUri, $ics): DavResult {
            if ($this->selftestMode && isset($this->store->objects[$this->calendarId($calendarUri)][$objectUri])) {
                throw new CalendarException('Conflito', 412);
            }
            $etag = $this->store->applyCreate($this->calendarId($calendarUri), $objectUri, $ics);
            return new DavResult(201, $etag);
        });
    }

    /** @inheritDoc */
    public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult {
        return $this->apply('update', [$calendarUri, $objectUri, $etag, $ics, $scheduling], function () use ($calendarUri, $objectUri, $etag, $ics, $scheduling): DavResult {
            if ($this->selftestMode && $this->store->object($this->calendarId($calendarUri), $objectUri)['etag'] !== $etag) {
                throw new CalendarException('Conflito', 412);
            }
            if ($scheduling && $this->schedule !== null) { $ics = ($this->schedule)($ics, 'PUT'); }
            return new DavResult(204, $this->store->applyUpdate($this->calendarId($calendarUri), $objectUri, $ics));
        });
    }

    /** @inheritDoc */
    public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult {
        return $this->apply('delete', [$calendarUri, $objectUri, $etag, $scheduling], function () use ($userId, $calendarUri, $objectUri, $scheduling): DavResult {
            if ($calendarUri === 'inbox' && $this->deleteInbox !== null) {
                ($this->deleteInbox)($userId, $objectUri);
                return new DavResult(204);
            }
            if ($scheduling && $this->schedule !== null) { ($this->schedule)($this->store->object($this->calendarId($calendarUri), $objectUri)['data'], 'DELETE'); }
            $this->store->applyDelete($this->calendarId($calendarUri), $objectUri);
            return new DavResult(204);
        });
    }

    /** @inheritDoc */
    public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult {
        return $this->apply('move', [$fromCalendarUri, $objectUri, $toCalendarUri, $etag], function () use ($fromCalendarUri, $objectUri, $toCalendarUri): DavResult {
            $from = $this->calendarId($fromCalendarUri);
            $to = $this->calendarId($toCalendarUri);
            if (!isset($this->store->objects[$from][$objectUri])) {
                throw CalendarException::notFound();
            }
            if ($this->selftestMode && isset($this->store->objects[$to][$objectUri])) { throw new CalendarException('Conflito', 412); }
            $this->store->applyMove($from, $objectUri, $to);
            return new DavResult(201);
        });
    }

    /** @inheritDoc */
    public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult {
        return $this->apply('mkcalendar', [$calendarUri, $displayName], function () use ($userId, $calendarUri): DavResult {
            if ($this->selftestMode) { $this->store->addCalendar('principals/users/' . $userId, count($this->store->objects) + 100, $calendarUri, 'principals/users/' . $userId); }
            if ($this->afterMkcalendar !== null) { ($this->afterMkcalendar)(); }
            return new DavResult(201);
        });
    }

    /** @inheritDoc */
    public function deleteCalendar(string $userId, string $calendarUri): DavResult {
        return $this->apply('deleteCalendar', [$calendarUri], function () use ($userId, $calendarUri): DavResult {
            if ($this->selftestMode) {
                foreach ($this->store->calendars['principals/users/' . $userId] as &$row) {
                    if ($row['uri'] === $calendarUri) { $row['deleted'] = true; }
                }
            }
            return new DavResult(204);
        });
    }

    /** @inheritDoc */
    public function probeWrite(string $actorUid, string $ownerUid, string $calendarUri, string $objectUri, string $ics): DavResult {
        throw new CalendarException('Proibido', 403);
    }

    /**
     * @param string $calendarUri calendar URI
     * @return int the backend calendar id
     * @throws CalendarException when the URI is not one of the store's calendars
     */
    private function calendarId(string $calendarUri): int {
        foreach ($this->store->calendars as $calendars) {
            foreach ($calendars as $calendar) {
                if ($calendar['uri'] === $calendarUri) {
                    return $calendar['id'];
                }
            }
        }
        throw CalendarException::notFound();
    }

    /**
     * @param string $operation operation name
     * @param array<string, mixed> $arguments arguments to record
     * @param callable(): DavResult $apply what to do when nothing refuses the call
     * @return DavResult
     * @throws Throwable the configured failure, or the CalendarException the pipeline would raise
     */
    private function apply(string $operation, array $arguments, callable $apply): DavResult {
        $this->calls[] = [$operation, $arguments];
        if (isset($this->failureFor[$operation])) {
            throw $this->failureFor[$operation];
        }
        if (isset($this->statusFor[$operation])) {
            return new DavResult($this->statusFor[$operation]);
        }
        return $apply();
    }
}