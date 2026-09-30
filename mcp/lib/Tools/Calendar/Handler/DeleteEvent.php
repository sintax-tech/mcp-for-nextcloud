<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Tools\Calendar\TrashPolicy;

/**
 * calendar_delete_event: deletes a whole event series into the CalDAV trash, never permanently.
 */
final class DeleteEvent implements CalendarTool {
    /**
     * @param CalendarAccess $access calendar visibility and ACL
     * @param CalendarStore $store calendar storage port
     * @param EventRepository $events event lookup with classification and ETag checks
     * @param TrashPolicy $trash CalDAV trash retention
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private EventRepository $events,
        private TrashPolicy $trash,
    ) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_delete_event',
            'Exclui um evento (a série inteira), que vai para a lixeira do calendário. Exige confirm: true.' . ToolSchema::NO_NOTIFICATION,
            'delete',
            ['calendar' => ToolSchema::calendar(), 'uid' => ToolSchema::uid(), 'confirm' => ToolSchema::confirm(), 'etag' => ToolSchema::etag()],
            ['calendar', 'uid', 'confirm'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, confirm: true, etag?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, calendar, deleted, recoverable}
     * @throws CalendarException when not writable, protected, changed meanwhile or the trash is disabled
     */
    public function execute(array $arguments, string $userId): array {
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if (!$this->trash->recoverable()) {
            throw CalendarException::blocked('Exclusão bloqueada: a lixeira do calendário está desativada e o evento não poderia ser recuperado.');
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        $this->store->delete($calendar->id, $stored->uri);
        return ToolSchema::result(['uid' => $arguments['uid'], 'calendar' => $calendar->path, 'deleted' => true, 'recoverable' => true]);
    }
}
