<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarTool;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Locating\EventExpander;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarFailures;
use RuntimeException;

/**
 * calendar_list_events: occurrences overlapping a window of at most 366 days, capped at 2 000 items.
 */
final class ListEventsHandler implements CalendarTool {
	/** Most items returned by one call; beyond it the list is cut and flagged. */
	public const MAX_ITEMS = 2000;

	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 * @param CalendarStore $store calendar storage port
	 * @param EventRepository $events iCalendar parsing
	 * @param Classification $classification CLASS rules
	 * @param EventExpander $expander recurrence expansion
	 * @param EventMapper $mapper output mapping
	 * @param DateInput $dates date parsing
	 * @param ITimeFactory $time clock
	 * @param LoggerInterface $logger logger (never receives event content)
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private CalendarStore $store,
		private EventRepository $events,
		private Classification $classification,
		private EventExpander $expander,
		private EventMapper $mapper,
		private DateInput $dates,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {}

	/**
	 * @return array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}
	 */
	public function definition(): array {
		return ToolSchema::definition('calendar_list_events', CalendarMessages::TOOL_LIST_EVENTS, 'read', [
			'calendar' => ToolSchema::calendar(CalendarMessages::propertyDescription('calendar-optional')),
			'from' => ToolSchema::date(CalendarMessages::propertyDescription('from')),
			'to' => ToolSchema::date(CalendarMessages::propertyDescription('to')),
		]);
	}

	/**
	 * @param array{calendar?: string, from?: string, to?: string} $arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} items, plus {truncated, limit} when cut
	 * @throws \OCA\Mcp\Modules\Calendar\Failures\CalendarException when the calendar is not visible or a limit is exceeded
	 * @throws \OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException on invalid dates
	 */
	public function execute(array $arguments, string $userId): array {
		[$from, $to] = $this->dates->window($arguments['from'] ?? null, $arguments['to'] ?? null, $this->time->now());
		$calendars = isset($arguments['calendar'])
			? [$this->access->resolve($userId, $arguments['calendar'])]
			: $this->access->visible($userId);
		$items = [];
		foreach ($calendars as $calendar) {
			foreach ($this->store->objects($calendar->id, $this->store->eventUrisInRange($calendar->id, $from, $to)) as $row) {
				$vcalendar = $this->events->parse($row['data']);
				if ($vcalendar === null) {
					$this->logger->warning('MCP calendar: invalid calendar object skipped', ['app' => 'mcp', 'exception_class' => 'Sabre\VObject\ParseException']);
					continue;
				}
				foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
					// The CLASS belongs to the component the occurrence comes from: a private override of a public series
					// is hidden alone, and an override with no CLASS takes the one of its master.
					$visibility = $this->classification->visibilityOf($occurrence['event'], $vcalendar, $calendar, $userId);
					if ($visibility === Classification::HIDDEN) {
						continue;
					}
					if (count($items) >= self::MAX_ITEMS) {
						return ToolSchema::result($items, ['truncated' => true, 'limit' => self::MAX_ITEMS]);
					}
					$items[] = $this->mapper->toItem($occurrence['event'], $occurrence['start'], $occurrence['end'], $calendar, $visibility);
				}
			}
		}
		return ToolSchema::result($items);
	}

	/**
	 * The read as the module answers it: a refusal the user can read becomes an error result.
	 *
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @return array<string, mixed> the MCP result, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function handle(string $userId, array $arguments): array {
		return CalendarFailures::run(fn (): array => $this->execute($arguments, $userId));
	}
}
