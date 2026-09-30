<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

/**
 * Moves an event between two calendars the user can write, keeping its UID and object URI.
 * Move stays within one owner; transfer crosses owners. Both refuse to overwrite.
 */
class EventRelocator {
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events event lookup with classification and ETag checks
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private EventRepository $events,
    ) {}

    /**
     * @param string $userId acting user
     * @param string $sourcePath source calendar path
     * @param string $uid event UID
     * @param string $targetPath target calendar path
     * @param string|null $etag expected ETag
     * @param bool $crossOwner true for transfer (owners must differ), false for move (same owner)
     * @return array{uid:string, from:string, to:string}
     * @throws CalendarException when a calendar is not writable, the mode does not match the owners,
     *                           the event is protected or changed, or the target already has it
     */
    public function relocate(string $userId, string $sourcePath, string $uid, string $targetPath, ?string $etag, bool $crossOwner): array {
        $source = $this->access->resolveWritable($userId, $sourcePath);
        $target = $this->access->resolveWritable($userId, $targetPath);
        if ($source->id === $target->id) {
            throw CalendarException::conflict('origem e destino são o mesmo calendário.');
        }
        $sameOwner = $source->ownerPrincipal === $target->ownerPrincipal;
        if (!$crossOwner && !$sameOwner) {
            throw CalendarException::blocked('Os calendários têm donos diferentes; use calendar_transfer_event.');
        }
        if ($crossOwner && $sameOwner) {
            throw CalendarException::blocked('Os calendários têm o mesmo dono; use calendar_move_event.');
        }
        $stored = $this->events->forChange($source, $uid, $userId, $etag);
        $this->events->assertFree($target, $uid, $stored->uri);
        if (!$this->store->move($source->ownerPrincipal, $stored->id, $target->ownerPrincipal, $target->id, $stored->uri)) {
            throw CalendarException::blocked('Não foi possível mover o evento; tente novamente.');
        }
        return ['uid' => $uid, 'from' => $source->path, 'to' => $target->path];
    }
}
