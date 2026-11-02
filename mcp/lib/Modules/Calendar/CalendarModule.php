<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventHandler;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventPreview;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventHandler;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventPreview;
use OCA\Mcp\Modules\Calendar\Reading\ListCalendarsHandler;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventPreview;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventHandler;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventPreview;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventDraft;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventHandler;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventPreview;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use RuntimeException;
use OCA\Mcp\Modules\Calendar\Approval\CalendarDraft;
use OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarGuideNotes;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarPlanRenderer;
use OCA\Mcp\Tools\Calendar\CalendarTool;

/**
 * Calendar tool module: every tool is exposed; the registry has already checked the admin grant
 * (writes are off by default), the app and the input schema, and asks {@see self::preview()} for the
 * plan of every write that arrives without `confirm: true`. Each read, plan and confirmed write has its own work
 * class; this module only finds it by name.
 */
final class CalendarModule implements ToolModule, PreviewsWrites, ToolGuideNotes, RendersPlans {
	/** @var array<string, CalendarTool> handlers by tool name, read for the published definitions */
	private array $tools = [];

	/** @var array<string, ListCalendarsHandler|ListEventsHandler> reads by tool name */
	private array $reads;

	/** @var array<string, CreateEventHandler|UpdateEventHandler|MoveEventHandler|DeleteEventHandler|TransferEventHandler> confirmed writes by tool name */
	private array $effects;

	/** @var array<string, CreateEventPreview|UpdateEventPreview|MoveEventPreview|DeleteEventPreview|TransferEventPreview> write plans by tool name */
	private array $plans;

	/**
	 * @param ListCalendarsHandler $listCalendars calendar_list_calendars
	 * @param ListEventsHandler $listEvents calendar_list_events
	 * @param CreateEventDraft $createEvent calendar_create_event
	 * @param UpdateEventDraft $updateEvent calendar_update_event
	 * @param MoveEventDraft $moveEvent calendar_move_event
	 * @param DeleteEventDraft $deleteEvent calendar_delete_event
	 * @param TransferEventDraft $transferEvent calendar_transfer_event
	 * @param CalendarDraftApproval $approval the confirmation every write goes through
	 * @param CalendarPlanRenderer|null $planRenderer Markdown of the plans, the default renderer when null
	 */
	public function __construct(
		ListCalendarsHandler $listCalendars,
		ListEventsHandler $listEvents,
		CreateEventDraft $createEvent,
		UpdateEventDraft $updateEvent,
		MoveEventDraft $moveEvent,
		DeleteEventDraft $deleteEvent,
		TransferEventDraft $transferEvent,
		CalendarDraftApproval $approval,
		private ?CalendarPlanRenderer $planRenderer = null,
	) {
		$this->planRenderer ??= new CalendarPlanRenderer();
		foreach ([$listCalendars, $listEvents, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent] as $tool) {
			$this->tools[$tool->definition()['name']] = $tool;
		}
		$this->reads = ['calendar_list_calendars' => $listCalendars, 'calendar_list_events' => $listEvents];
		$this->effects = [
			'calendar_create_event' => new CreateEventHandler($approval, $createEvent),
			'calendar_update_event' => new UpdateEventHandler($approval, $updateEvent),
			'calendar_move_event' => new MoveEventHandler($approval, $moveEvent),
			'calendar_delete_event' => new DeleteEventHandler($approval, $deleteEvent),
			'calendar_transfer_event' => new TransferEventHandler($approval, $transferEvent),
		];
		$this->plans = [
			'calendar_create_event' => new CreateEventPreview($approval, $createEvent),
			'calendar_update_event' => new UpdateEventPreview($approval, $updateEvent),
			'calendar_move_event' => new MoveEventPreview($approval, $moveEvent),
			'calendar_delete_event' => new DeleteEventPreview($approval, $deleteEvent),
			'calendar_transfer_event' => new TransferEventPreview($approval, $transferEvent),
		];
	}

	/**
	 * What the schemas cannot say: how an event is found, and why a write here answers with a plan first.
	 *
	 * @return list<string> behaviour notes of the Calendar tools
	 */
	public function guideNotes(): array {
		return CalendarGuideNotes::notes();
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
	 * @param string $name write tool name
	 * @param array<string, mixed> $arguments arguments validated by the registry
	 * @param string $userId authenticated UID
	 * @return array<string, mixed> the plan, as the structured content of a non-error result
	 * @throws InvalidArgumentException for an unknown tool (-32602)
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		$plans = $this->plans[$name] ?? throw new InvalidArgumentException('Unknown tool');
		return $plans->plan($userId, $arguments);
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
	 * Runs a read, or the write the user confirmed.
	 *
	 * @param string $name tool name
	 * @param array<string, mixed> $arguments arguments validated by the registry
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
	 * @throws InvalidArgumentException for an unknown tool or an argument the schema cannot validate (-32602)
	 * @throws RuntimeException wrapping any other failure, which the registry reports generically
	 */
	public function call(string $name, array $arguments, string $userId): array {
		$work = $this->reads[$name] ?? $this->effects[$name] ?? throw new InvalidArgumentException('Unknown tool');
		return $work->handle($userId, $arguments);
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
		if (!$tool instanceof CalendarDraft) { return $definition; }
		$schema = &$definition['inputSchema'];
		$properties = (array)$schema['properties'];
		$properties['confirm_shared'] = SharedGuard::property();
		$schema['properties'] = (object)$properties;
		$definition['description'] .= ' First returns a detailed plan without writing. Show it to the user and wait for explicit approval; then repeat the same arguments with confirm=true, adding the etag returned in the plan when it has one.';
		return $definition;
	}
}
