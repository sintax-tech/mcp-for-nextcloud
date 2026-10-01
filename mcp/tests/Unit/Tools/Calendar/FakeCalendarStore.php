<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use Throwable;

/**
 * In-memory CalendarStore, read-only on the tool side, with the writes applied by FakeCalendarDav.
 *
 * The store exposes no write method of its own: the only way an object changes here is a dispatched
 * DAV request, which is what makes "nothing was dispatched" a meaningful assertion.
 */
final class FakeCalendarStore implements CalendarStore {
    /** @var array<string, list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}>> calendars by principal */
    public array $calendars = [];
    /** @var array<int, array<string, array{id:int, uri:string, etag:string, data:string, deleted:bool}>> objects by calendar id and URI */
    public array $objects = [];
    /** @var array<int, list<array{principal:string, readOnly:bool}>> shares by calendar id */
    public array $shares = [];
    /** Failure thrown by every read method when set. */
    public ?Throwable $failure = null;
    /** Result returned by moveCalendarObject in the fake DAV. */
    public bool $moveResult = true;
    private int $nextObjectId = 1000;

    /**
     * @param string $principal principal URI
     * @param int $id calendar id
     * @param string $uri calendar URI
     * @param string $owner owner principal
     * @param array{readOnly?: bool, components?: list<string>, deleted?: bool, name?: string} $options row options
     */
    public function addCalendar(string $principal, int $id, string $uri, string $owner, array $options = []): void {
        $this->calendars[$principal][] = [
            'id' => $id,
            'uri' => $uri,
            'displayName' => $options['name'] ?? $uri,
            'ownerPrincipal' => $owner,
            'readOnly' => $options['readOnly'] ?? false,
            'components' => $options['components'] ?? ['VEVENT', 'VTODO'],
            'deleted' => $options['deleted'] ?? false,
        ];
        $this->objects[$id] ??= [];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $principal sharee principal, "principals/users/<uid>" or "principals/groups/<gid>"
     * @param bool $readOnly whether the share is read-only
     */
    public function addShare(int $calendarId, string $principal, bool $readOnly = false): void {
        $this->shares[$calendarId][] = ['principal' => $principal, 'readOnly' => $readOnly];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $uri object URI
     * @param string $data iCalendar text
     * @param bool $deleted whether the object is in the trash
     */
    public function addObject(int $calendarId, string $uri, string $data, bool $deleted = false): void {
        $this->objects[$calendarId][$uri] = ['id' => $this->nextObjectId++, 'uri' => $uri, 'etag' => '"' . md5($data) . '"', 'data' => $data, 'deleted' => $deleted];
    }

    /** @return list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}> */
    public function calendarsForPrincipal(string $principalUri): array {
        $this->fail();
        return $this->calendars[$principalUri] ?? [];
    }

    /** @return list<string> */
    public function eventUrisInRange(int $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array {
        $this->fail();
        return array_keys(array_filter($this->objects[$calendarId] ?? [], static fn (array $o) => !$o['deleted']));
    }

    /** @return list<array{id:int, uri:string, etag:string, data:string, deleted:bool}> */
    public function objects(int $calendarId, array $uris): array {
        $this->fail();
        return array_values(array_intersect_key($this->objects[$calendarId] ?? [], array_flip($uris)));
    }

    /** @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null */
    public function object(int $calendarId, string $uri): ?array {
        $this->fail();
        return $this->objects[$calendarId][$uri] ?? null;
    }

    /** @return array{id:int, uri:string, etag:string, data:string, deleted:bool}|null */
    public function objectByUid(int $calendarId, string $uid): ?array {
        $this->fail();
        foreach ($this->objects[$calendarId] ?? [] as $object) {
            if (!$object['deleted'] && preg_match('/^UID:' . preg_quote($uid, '/') . '\r?$/m', $object['data']) === 1) {
                return $object;
            }
        }
        return null;
    }

    /** @return list<array{principal:string, readOnly:bool}> */
    public function sharesOf(int $calendarId): array {
        $this->fail();
        return $this->shares[$calendarId] ?? [];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $uri object URI
     * @param string $data iCalendar text
     * @return string the new ETag
     */
    public function applyCreate(int $calendarId, string $uri, string $data): string {
        $this->addObject($calendarId, $uri, $data);
        return $this->objects[$calendarId][$uri]['etag'];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $uri object URI
     * @param string $data iCalendar text
     * @return string the new ETag
     */
    public function applyUpdate(int $calendarId, string $uri, string $data): string {
        $id = $this->objects[$calendarId][$uri]['id'];
        $this->objects[$calendarId][$uri] = ['id' => $id, 'uri' => $uri, 'etag' => '"' . md5($data) . '"', 'data' => $data, 'deleted' => false];
        return $this->objects[$calendarId][$uri]['etag'];
    }

    /**
     * @param int $calendarId calendar id
     * @param string $uri object URI
     * @return void
     */
    public function applyDelete(int $calendarId, string $uri): void {
        $this->objects[$calendarId][$uri]['deleted'] = true;
    }

    /**
     * @param int $fromId source calendar id
     * @param string $uri object URI
     * @param int $toId destination calendar id
     * @return void
     */
    public function applyMove(int $fromId, string $uri, int $toId): void {
        $row = $this->objects[$fromId][$uri];
        unset($this->objects[$fromId][$uri]);
        $this->objects[$toId][$uri] = $row;
    }

    private function fail(): void {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}