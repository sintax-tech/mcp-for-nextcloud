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
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\ToolModule;
use RuntimeException;

/**
 * Calendar tool module: reads are always exposed, writes only after the selftest proved the DAV
 * pipeline on this server (see CalendarWriteGate). The registry has already checked grant, app and
 * input schema for exposed tools, and asks {@see self::preview()} for the plan of every write that
 * arrives without `confirm: true`.
 */
final class CalendarModule implements ToolModule, PreviewsWrites {
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
     * @param CalendarWriteGate $gate which operations the verification opened
     */
    public function __construct(
        ListCalendars $listCalendars,
        ListEvents $listEvents,
        CreateEvent $createEvent,
        UpdateEvent $updateEvent,
        MoveEvent $moveEvent,
        DeleteEvent $deleteEvent,
        TransferEvent $transferEvent,
        private CalendarWriteGate $gate,
        private CalendarDraftApproval $approval,
    ) {
        foreach ([$listCalendars, $listEvents, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent] as $tool) {
            $this->tools[$tool->definition()['name']] = $tool;
        }
    }

    /**
     * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
     */
    public function definitions(): array {
        $operations = $this->gate->operations();
        return array_values(array_map(
            fn (CalendarTool $tool) => $this->publicDefinition($tool),
            array_filter($this->tools, static fn (CalendarTool $tool): bool => in_array($tool->definition()['operation'], $operations, true)),
        ));
    }

    /**
     * The plan of a write, which the registry asks for when the call arrives without `confirm: true`.
     *
     * The draft itself lives in {@see CalendarDraftApproval}: preparing it is what reads the event, its
     * ETag and the calendars it would touch, and nothing is dispatched here.
     *
     * @param string $name write tool name
     * @param array<string, mixed> $arguments arguments validated by the registry
     * @param string $userId authenticated UID
     * @return array<string, mixed> the plan, as the structured content of a non-error result
     * @throws InvalidArgumentException for an unknown or unavailable tool (-32602)
     * @throws RuntimeException wrapping any other failure, which the registry reports generically
     */
    public function preview(string $name, array $arguments, string $userId): array {
        $tool = $this->tools[$name] ?? throw new InvalidArgumentException('Unknown tool');
        if (!in_array($tool->definition()['operation'], $this->gate->operations(), true)) {
            throw new InvalidArgumentException('Unknown tool');
        }
        if (($arguments['send_invitations'] ?? false) === true && !$this->gate->invitationsVerified()) {
            throw new CalendarException(CalendarMessages::invitationsUnverified());
        }
        if (!$tool instanceof CalendarWriteTool) {
            throw new InvalidArgumentException('Unknown tool');
        }
        return $this->approval->preview($tool, $arguments, $userId);
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
        return $this->write($name, $arguments, $userId, fn (CalendarWriteTool $tool): array => $this->approval->execute($tool, $arguments, $userId));
    }

    /**
     * Resolves the tool, refuses explicit scheduling before the delivery proof, and runs one of the two
     * halves of a write: its plan, or the write the user confirmed.
     *
     * @param string $name tool name
     * @param array<string, mixed> $arguments arguments validated by the registry
     * @param string $userId authenticated UID
     * @param callable(CalendarWriteTool): array $run what to do with a write tool
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
     * @throws InvalidArgumentException for an unknown tool (-32602)
     * @throws RuntimeException wrapping any other failure, which the registry reports generically
     */
    private function write(string $name, array $arguments, string $userId, callable $run): array {
        $tool = $this->tools[$name] ?? throw new InvalidArgumentException('Unknown tool');
        if (!in_array($tool->definition()['operation'], $this->gate->operations(), true)) {
            throw new InvalidArgumentException('Unknown tool');
        }
        // Fail closed for explicit scheduling until the optional internal-delivery proof passed.
        // This also covers cancellation and existing guests not present in the arguments.
        if (($arguments['send_invitations'] ?? false) === true && !$this->gate->invitationsVerified()) {
            return ToolSchema::error(CalendarMessages::invitationsUnverified());
        }
        try {
            return $tool instanceof CalendarWriteTool ? $run($tool) : $tool->execute($arguments, $userId);
        } catch (CalendarException $e) {
            return ToolSchema::error($e->getMessage());
        } catch (CalendarArgumentException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            // A library InvalidArgumentException must not become -32602 nor leak its message.
            throw new RuntimeException('Calendar backend failure', 0, $e);
        }
    }
    /**
     * The published schema of a write: the shared acknowledgement, and the note that asks for the plan first.
     * `confirm` itself is not added here — the registry publishes it on every write tool of the app.
     */
    private function publicDefinition(CalendarTool $tool): array {
        $definition = $tool->definition();
        if (!$tool instanceof CalendarWriteTool) { return $definition; }
        $schema = &$definition['inputSchema'];
        $properties = (array)$schema['properties'];
        $properties['confirm_shared'] = SharedGuard::property();
        $schema['properties'] = (object)$properties;
        $definition['description'] .= ' First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.';
        return $definition;
    }

}
