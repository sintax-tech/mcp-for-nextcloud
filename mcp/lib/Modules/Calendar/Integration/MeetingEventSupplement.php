<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Integration;

use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventExpander;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Walks the objects calendar_list_events already read and copies attendees and links onto full occurrences.
 *
 * A confidential or private occurrence the reader hides or redacts gets neither. The public tool is not this class.
 */
final class MeetingEventSupplement implements MeetingOccurrenceFacts {
	/** Same encoding calendar_list_events uses for one text block. */
	private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

	/**
	 * @param ScopedCalendarAccess $access the calendars the reader may reach
	 * @param CalendarStore $store calendar storage port
	 * @param EventRepository $events iCalendar parsing
	 * @param Classification $classification CLASS rules
	 * @param EventExpander $expander recurrence expansion
	 * @param EventMapper $mapper the key the reader prints for one occurrence
	 * @param DateInput $dates date parsing
	 * @param ITimeFactory $time clock
	 * @param LoggerInterface $logger logger (never receives event content)
	 * @param MeetingEventFacts $facts participants and links of one full occurrence
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
		private MeetingEventFacts $facts,
	) {
	}

	/** {@inheritDoc} */
	public function apply(string $userId, array $arguments, array $result): array {
		if (($result['isError'] ?? false) === true || !is_array($result['content'] ?? null)) {
			return $result;
		}
		$facts = $this->walk($userId, $arguments);
		$index = 0;
		$content = [];
		foreach ($result['content'] as $block) {
			$content[] = is_array($block) ? $this->block($block, $facts, $index) : $block;
		}
		$result['content'] = $content;
		return $result;
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the listing window
	 * @return list<array{uid: string, start: string, calendar: string, extra: array<string, mixed>}> one row per occurrence the reader emits
	 */
	private function walk(string $userId, array $arguments): array {
		[$from, $to] = $this->dates->window(isset($arguments['from']) ? (string)$arguments['from'] : null, isset($arguments['to']) ? (string)$arguments['to'] : null, $this->time->now());
		$calendars = isset($arguments['calendar'])
			? [$this->access->resolve($userId, (string)$arguments['calendar'])]
			: $this->access->visible($userId);
		$rows = [];
		foreach ($calendars as $calendar) {
			foreach ($this->store->objects($calendar->id, $this->store->eventUrisInRange($calendar->id, $from, $to)) as $object) {
				$vcalendar = $this->events->parse($object['data']);
				if ($vcalendar === null) {
					$this->logger->warning('MCP calendar: invalid calendar object skipped: {exception_class}', ['app' => 'mcp', 'exception_class' => 'Sabre\VObject\ParseException']);
					continue;
				}
				foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
					$visibility = $this->classification->visibilityOf($occurrence['event'], $vcalendar, $calendar, $userId);
					if ($visibility === Classification::HIDDEN) {
						continue;
					}
					if (count($rows) >= ListEventsHandler::MAX_ITEMS) {
						return $rows;
					}
					$item = $this->mapper->toItem($occurrence['event'], $occurrence['start'], $occurrence['end'], $calendar, $visibility);
					$rows[] = [
						'uid' => (string)$item['uid'],
						'start' => (string)$item['start'],
						'calendar' => (string)$item['calendar'],
						'extra' => $visibility === Classification::FULL ? $this->facts->of($occurrence['event']) : [],
					];
				}
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $block one MCP content block
	 * @param list<array{uid: string, start: string, calendar: string, extra: array<string, mixed>}> $facts facts aligned with the reader
	 * @param int $index the next occurrence in that list
	 * @return array<string, mixed> the block, with full occurrences extended
	 */
	private function block(array $block, array $facts, int &$index): array {
		$decoded = json_decode((string)($block['text'] ?? ''), true);
		if (!is_array($decoded)) {
			return $block;
		}
		$list = array_is_list($decoded);
		$rows = $list ? $decoded : [$decoded];
		$changed = false;
		foreach ($rows as $at => $row) {
			if (!is_array($row) || !isset($row['uid'], $row['start'])) {
				continue;
			}
			$fact = $facts[$index] ?? null;
			$index++;
			if (!is_array($fact) || $fact['extra'] === [] || (string)$row['uid'] !== $fact['uid'] || (string)$row['start'] !== $fact['start'] || (string)($row['calendar'] ?? '') !== $fact['calendar']) {
				continue;
			}
			$rows[$at] = $row + $fact['extra'];
			$changed = true;
		}
		if (!$changed) {
			return $block;
		}
		$block['text'] = json_encode($list ? array_values($rows) : $rows[0], self::JSON);
		return $block;
	}
}
