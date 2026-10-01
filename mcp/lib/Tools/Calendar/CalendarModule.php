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
use OCA\Mcp\Tools\RendersPlans;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use RuntimeException;

/**
 * Calendar tool module: every tool is exposed; the registry has already checked the admin grant
 * (writes are off by default), the app and the input schema, and asks {@see self::preview()} for the
 * plan of every write that arrives without `confirm: true`.
 */
final class CalendarModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
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
        private CalendarDraftApproval $approval,
        private ?CalendarPlanRenderer $planRenderer = null,
    ) {
        $this->planRenderer ??= new CalendarPlanRenderer();
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
            'The plan of a create, update (when the time or the guests change), move or transfer can carry warnings: a '
                . 'collision with another event of the target calendar, a participant who is busy or whose agenda could '
                . 'not be checked, and a calendar that is not shared with the participants. Each warning has a type '
                . '(collision, busy, unverifiable, calendarNotShared) and a message to read to the user. They never block: '
                . 'tell the user and repeat the call with confirm: true only if the answer is still yes. When '
                . 'sharedCalendars lists calendars the participants already see and suggestedCalendar is set, offer it; '
                . 'when there are several, ask the user which one to use and repeat the call with that calendar. Other '
                . 'people\'s appointments are only ever reported as busy, never with their content. When an update '
                . 'moves an event, the event\'s own slot is left out of the availability check of the guests it already '
                . 'has (their copy of the invitation would otherwise make them busy); the collision check always leaves '
                . 'the event itself out. An appointment of a guest sitting entirely inside the old slot is not reported.',
            'Dates and times arrive as they will be stored, and are read back in the timezone of the account; a '
                . 'recurring event is expanded into its occurrences before it reaches the user.',
            'send_invitations hands the invitation to the CalDAV scheduling of the server. The plan and the result '
                . 'say whether the server has e-mail invitations switched on (imipEnabled); when it is off, say so to '
                . 'the user instead of claiming an e-mail was sent.',
            'A calendar owned by somebody else (a share) needs confirm_shared after asking the user, and a transfer '
                . 'moves the event there and removes it from here.',
            'A deleted event goes to the Nextcloud trash bin of the calendar; with retention at 0 the deletion is '
                . 'refused rather than permanent.',
            'The writing tools appear only when the administrator granted them to this user; a module showing reads '
                . 'only means the writing grants are off.',
        ];
    }

    /**
     * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
     */
    public function definitions(): array {
        return array_values(array_map(fn (CalendarTool $tool) => $this->publicDefinition($tool), $this->tools));
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
     * @throws InvalidArgumentException for an unknown tool (-32602)
     * @throws RuntimeException wrapping any other failure, which the registry reports generically
     */
    public function preview(string $name, array $arguments, string $userId): array {
        $tool = $this->tools[$name] ?? throw new InvalidArgumentException('Unknown tool');
        if (!$tool instanceof CalendarWriteTool) {
            throw new InvalidArgumentException('Unknown tool');
        }
        return $this->approval->preview($tool, $arguments, $userId);
    }

    /**
     * Renders a write plan as Markdown for the user confirming it.
     *
     * @param string $tool tool name
     * @param array<string, mixed> $plan plan returned by preview()
     * @return string|null Markdown body or null to fall back to the generic renderer
     */
    public function renderPlan(string $tool, array $plan): ?string {
        return $this->planRenderer->renderPlan($tool, $plan);
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
     * Resolves the tool and runs a read, or the write the user confirmed.
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
     *
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app?:string, destructiveHint?:bool} public tool definition with shared-write confirmation schema when applicable
     *
     * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string, destructiveHint?:bool} public tool definition with shared-write confirmation schema when applicable
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
