<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Handler;

use OCA\Mcp\Tools\Calendar\CalendarMessages;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Tools\Calendar\EventRelocator;
use OCA\Mcp\Tools\Calendar\ToolSchema;

/**
 * calendar_transfer_event: moves an event to a calendar of another owner that the user can write.
 */
final class TransferEvent implements CalendarTool {
    /**
     * @param EventRelocator $relocator shared move/transfer logic
     */
    public function __construct(private EventRelocator $relocator) {}

    /**
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
     */
    public function definition(): array {
        return ToolSchema::definition(
            'calendar_transfer_event',
            'Transfere um evento para um calendário de outro usuário compartilhado com você com permissão de escrita. Exige confirm: true. Eventos com participantes são recusados: o organizador continua sendo você e o Nextcloud não avisa ninguém nessa operação.' . CalendarMessages::PARTICIPANTS_NOT_NOTIFIED,
            'transfer',
            [
                'calendar' => ToolSchema::calendar('path do calendário de origem'),
                'uid' => ToolSchema::uid(),
                'targetCalendar' => ToolSchema::calendar('path do calendário de destino, de outro dono'),
                'confirm' => ToolSchema::confirm(),
                'etag' => ToolSchema::etag(),
            ],
            ['calendar', 'uid', 'targetCalendar', 'confirm'],
        );
    }

    /**
     * @param array{calendar: string, uid: string, targetCalendar: string, confirm: true, etag?: string} $arguments
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>} {uid, from, to}
     * @throws \OCA\Mcp\Tools\Calendar\CalendarException when the transfer is not allowed or conflicts
     */
    public function execute(array $arguments, string $userId): array {
        return ToolSchema::result($this->relocator->relocate($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, true));
    }
}
