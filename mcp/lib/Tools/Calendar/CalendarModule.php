<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Tools\Calendar\Handler\CreateEvent;
use OCA\Mcp\Tools\Calendar\Handler\DeleteEvent;
use OCA\Mcp\Tools\Calendar\Handler\ListCalendars;
use OCA\Mcp\Tools\Calendar\Handler\ListEvents;
use OCA\Mcp\Tools\Calendar\Handler\MoveEvent;
use OCA\Mcp\Tools\Calendar\Handler\TransferEvent;
use OCA\Mcp\Tools\Calendar\Handler\UpdateEvent;
use OCA\Mcp\Tools\ToolModule;
use RuntimeException;

/**
 * Calendar tool module: exposes reads and keeps unproved writes fail-closed behind the module boundary.
 * The registry has already checked grant, app and input schema for exposed tools.
 */
final class CalendarModule implements ToolModule {
    /** Tool names whose read path is currently proved and enabled. */
    private const ENABLED_TOOLS = ['calendar_list_calendars', 'calendar_list_events'];
    /** @var list<string> write tool names kept for later reactivation after DAV proof */
    private const DISABLED_WRITES = [
        'calendar_create_event',
        'calendar_update_event',
        'calendar_move_event',
        'calendar_delete_event',
        'calendar_transfer_event',
    ];

    /** @var array<string, CalendarTool> handlers by tool name */
    private array $tools = [];

    /**
     * @param ListCalendars $listCalendars calendar_list_calendars
     * @param ListEvents $listEvents calendar_list_events
     * @param CreateEvent $createEvent calendar_create_event
     * @param UpdateEvent $updateEvent calendar_update_event
     * @param MoveEvent $moveEvent calendar_move_event
     * @param DeleteEvent $deleteEvent calendar_delete_event
     * @param TransferEvent $transferEvent calendar_transfer_event
     */
    public function __construct(
        ListCalendars $listCalendars,
        ListEvents $listEvents,
        CreateEvent $createEvent,
        UpdateEvent $updateEvent,
        MoveEvent $moveEvent,
        DeleteEvent $deleteEvent,
        TransferEvent $transferEvent,
    ) {
        foreach ([$listCalendars, $listEvents, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent] as $tool) {
            $this->tools[$tool->definition()['name']] = $tool;
        }
    }

    /**
     * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
     */
    public function definitions(): array {
        return array_values(array_map(
            static fn (CalendarTool $tool) => $tool->definition(),
            array_intersect_key($this->tools, array_flip(self::ENABLED_TOOLS)),
        ));
    }

    /**
     * @param string $name tool name
     * @param array<string, mixed> $arguments arguments validated by the registry
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
     * @throws InvalidArgumentException for an unknown tool or an argument the schema cannot validate (-32602)
     * @throws RuntimeException wrapping any other failure, which the registry reports generically
     */
    public function call(string $name, array $arguments, string $userId): array {
        if (in_array($name, self::DISABLED_WRITES, true)) {
            throw new InvalidArgumentException('Unknown tool');
        }
        $tool = $this->tools[$name] ?? throw new InvalidArgumentException('Unknown tool');
        try {
            return $tool->execute($arguments, $userId);
        } catch (CalendarException $e) {
            return ToolSchema::error($e->getMessage());
        } catch (CalendarArgumentException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            // A library InvalidArgumentException must not become -32602 nor leak its message.
            throw new RuntimeException('Calendar backend failure', 0, $e);
        }
    }
}
