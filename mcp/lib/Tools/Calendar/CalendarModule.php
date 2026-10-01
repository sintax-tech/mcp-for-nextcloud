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
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use RuntimeException;

/**
 * Calendar tool module: reads are always exposed, writes only after the selftest proved the DAV
 * pipeline on this server (see CalendarWriteGate). The registry has already checked grant, app and
 * input schema for exposed tools.
 */
final class CalendarModule implements ToolModule, ToolGuideNotes {
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
     * What the schemas cannot say: how an event is found, and why a write here answers with a plan first.
     *
     * @return list<string>
     */
    public function guideNotes(): array {
        return [
            'Calendars are addressed by path: calendar_list_calendars gives the paths of this user. Events are '
                . 'addressed by uid, and calendar_list_events gives the uids of a period.',
            'Every write answers with a plan first: what it would change, the etag of the object as it is now, and '
                . 'which invitations would go out. Nothing is written until the very same call is repeated with '
                . 'confirm: true, so show the plan to the user and wait for an explicit yes.',
            'Dates and times arrive as they will be stored, and are read back in the timezone of the account; a '
                . 'recurring event is expanded into its occurrences before it reaches the user.',
            'send_invitations stays refused on a server that has not proved CalDAV scheduling: without it no '
                . 'invitation leaves, and the call says so instead of pretending it was sent.',
            'A calendar owned by somebody else (a share) needs confirm_shared after asking the user, and a transfer '
                . 'moves the event there and removes it from here.',
            'A deleted event goes to the Nextcloud trash bin of the calendar; with retention at 0 the deletion is '
                . 'refused rather than permanent.',
            'The writing tools only appear at all once the DAV verification passed on this server. A module showing '
                . 'reads only means the verification is still closed, not that the grants are missing.',
        ];
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
     * @param string $name tool name
     * @param array<string, mixed> $arguments arguments validated by the registry
     * @param string $userId authenticated UID
     * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
     * @throws InvalidArgumentException for an unknown tool or an argument the schema cannot validate (-32602)
     * @throws RuntimeException wrapping any other failure, which the registry reports generically
     */
    public function call(string $name, array $arguments, string $userId): array {
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
            return $tool instanceof CalendarWriteTool ? $this->approval->call($tool, $arguments, $userId) : $tool->execute($arguments, $userId);
        } catch (CalendarException $e) {
            return ToolSchema::error($e->getMessage());
        } catch (CalendarArgumentException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            // A library InvalidArgumentException must not become -32602 nor leak its message.
            throw new RuntimeException('Calendar backend failure', 0, $e);
        }
    }
    /** Public approval controls are distinct from the direct selftest handler schemas. */
    private function publicDefinition(CalendarTool $tool): array {
        $definition = $tool->definition();
        if (!$tool instanceof CalendarWriteTool) { return $definition; }
        $schema = &$definition['inputSchema'];
        $properties = (array)$schema['properties'];
        $properties['confirm'] = ['type' => 'boolean', 'default' => false, 'description' => 'Set true only after the plan was shown to the user and the user explicitly said yes. Without it nothing is written.'];
        $properties['confirm_shared'] = SharedGuard::property();
        $schema['properties'] = (object)$properties;
        $schema['required'] = array_values(array_diff($schema['required'] ?? [], ['confirm']));
        $definition['description'] .= ' First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.';
        return $definition;
    }

}
