<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use Throwable;

/**
 * In-memory CalendarStore that records every write and can simulate a backend failure.
 */
final class FakeCalendarStore implements CalendarStore {
    /** @var array<string, list<array{id:int, uri:string, displayName:string, ownerPrincipal:string, readOnly:bool, components:list<string>, deleted:bool}>> calendars by principal */
    public array $calendars = [];
    /** @var array<int, array<string, array{id:int, uri:string, etag:string, data:string, deleted:bool}>> objects by calendar id and URI */
    public array $objects = [];
    /** @var list<array{0:string, 1:mixed}> write calls: [method, arguments] */
    public array $writes = [];
    /** Failure thrown by every method when set. */
    public ?Throwable $failure = null;
    /** Result returned by move(). */
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

    public function create(int $calendarId, string $uri, string $data): string {
        $this->fail();
        $this->writes[] = ['create', [$calendarId, $uri, $data]];
        $this->addObject($calendarId, $uri, $data);
        return $this->objects[$calendarId][$uri]['etag'];
    }

    public function update(int $calendarId, string $uri, string $data): string {
        $this->fail();
        $this->writes[] = ['update', [$calendarId, $uri, $data]];
        $id = $this->objects[$calendarId][$uri]['id'];
        $this->objects[$calendarId][$uri] = ['id' => $id, 'uri' => $uri, 'etag' => '"' . md5($data) . '"', 'data' => $data, 'deleted' => false];
        return $this->objects[$calendarId][$uri]['etag'];
    }

    public function move(string $sourceOwnerPrincipal, int $objectId, string $targetOwnerPrincipal, int $targetCalendarId, string $uri): bool {
        $this->fail();
        $this->writes[] = ['move', [$sourceOwnerPrincipal, $objectId, $targetOwnerPrincipal, $targetCalendarId, $uri]];
        return $this->moveResult;
    }

    public function delete(int $calendarId, string $uri): void {
        $this->fail();
        $this->writes[] = ['delete', [$calendarId, $uri]];
        $this->objects[$calendarId][$uri]['deleted'] = true;
    }

    private function fail(): void {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
