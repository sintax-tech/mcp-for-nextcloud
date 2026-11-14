<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventHandler;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventPreview;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventHandler;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventPreview;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventDraft;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventHandler;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventPreview;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventPreview;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventHandler;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventPreview;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;
use OCA\Mcp\Modules\Calendar\Reading\ListCalendarsHandler;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestTimesHandler;
use OCA\Mcp\Modules\Calendar\Tools\CheckAvailability;
use OCA\Mcp\Modules\Calendar\Tools\CreateEvent;
use OCA\Mcp\Modules\Calendar\Tools\DeleteEvent;
use OCA\Mcp\Modules\Calendar\Tools\ListCalendars;
use OCA\Mcp\Modules\Calendar\Tools\ListEvents;
use OCA\Mcp\Modules\Calendar\Tools\MoveEvent;
use OCA\Mcp\Modules\Calendar\Tools\SuggestTimes;
use OCA\Mcp\Modules\Calendar\Tools\TransferEvent;
use OCA\Mcp\Modules\Calendar\Tools\UpdateEvent;
use Psr\Container\ContainerInterface;

/**
 * Builds the Calendar catalog module from the services its unit tests used to hand the legacy constructor, wiring each
 * tool the way the app container autowires it.
 */
final class CalendarTestModule {
	/** Static builder only. */
	private function __construct() {
	}

	/**
	 * @param ListCalendarsHandler $listCalendars calendar_list_calendars
	 * @param ListEventsHandler $listEvents calendar_list_events
	 * @param CreateEventDraft $createEvent calendar_create_event
	 * @param UpdateEventDraft $updateEvent calendar_update_event
	 * @param MoveEventDraft $moveEvent calendar_move_event
	 * @param DeleteEventDraft $deleteEvent calendar_delete_event
	 * @param TransferEventDraft $transferEvent calendar_transfer_event
	 * @param CalendarDraftApproval $approval the confirmation every write goes through
	 * @param CheckAvailabilityHandler|null $availability optional bounded read for new feature cases
	 * @param SuggestTimesHandler|null $suggestions optional bounded search
	 * @return CalendarModule the catalog module over those services
	 */
	public static function build(
		ListCalendarsHandler $listCalendars,
		ListEventsHandler $listEvents,
		CreateEventDraft $createEvent,
		UpdateEventDraft $updateEvent,
		MoveEventDraft $moveEvent,
		DeleteEventDraft $deleteEvent,
		TransferEventDraft $transferEvent,
		CalendarDraftApproval $approval,
		?CheckAvailabilityHandler $availability = null,
		?SuggestTimesHandler $suggestions = null,
	): CalendarModule {
		return new CalendarModule(self::container(($availability === null ? [] : [CheckAvailability::class => new CheckAvailability($availability)]) + ($suggestions === null ? [] : [SuggestTimes::class => new SuggestTimes($suggestions)]) + [
			ListCalendars::class => new ListCalendars($listCalendars),
			ListEvents::class => new ListEvents($listEvents),
			CreateEvent::class => new CreateEvent(new CreateEventHandler($approval, $createEvent), new CreateEventPreview($approval, $createEvent)),
			UpdateEvent::class => new UpdateEvent(new UpdateEventHandler($approval, $updateEvent), new UpdateEventPreview($approval, $updateEvent)),
			MoveEvent::class => new MoveEvent(new MoveEventHandler($approval, $moveEvent), new MoveEventPreview($approval, $moveEvent)),
			DeleteEvent::class => new DeleteEvent(new DeleteEventHandler($approval, $deleteEvent), new DeleteEventPreview($approval, $deleteEvent)),
			TransferEvent::class => new TransferEvent(new TransferEventHandler($approval, $transferEvent), new TransferEventPreview($approval, $transferEvent)),
		]));
	}

	/**
	 * @param array<string, object> $services instances by id
	 * @return ContainerInterface a container that answers only those
	 */
	private static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
