<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Tasks\TaskStore;

/**
 * Calendars in memory for the hierarchy tests, which, unlike the listing double, keep objects apart by calendar
 * and by component: a parent in another calendar, an event with the parent's UID or a trashed parent are what a
 * test plants, and every lookup is counted.
 */
final class HierarchyCalendars implements CalendarStore, TaskStore {
	/** @var array<int, array<string, array{component:string, data:string, deleted:bool}>> objects by calendar and UID */
	public array $objects = [];

	/** Number of objectByUid() lookups. */
	public int $lookups = 0;

	/**
	 * Plants a task.
	 *
	 * @param int $calendarId calendar ID
	 * @param string $uid task UID
	 * @param string|null $parent UID of the parent, or null
	 * @param string $extra property lines placed before the summary
	 * @param bool $deleted whether the task is in the trash
	 * @return self this store
	 */
	public function task(int $calendarId, string $uid, ?string $parent = null, string $extra = '', bool $deleted = false): self {
		$relation = $parent === null ? '' : "RELATED-TO:$parent\r\n";
		$this->objects[$calendarId][$uid] = ['component' => 'VTODO', 'deleted' => $deleted, 'data' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\n"
			. "UID:$uid\r\nDTSTAMP:20261001T100000Z\r\n$relation$extra" . "SUMMARY:Task $uid\r\nEND:VTODO\r\nEND:VCALENDAR\r\n"];
		return $this;
	}

	/**
	 * Plants a chain root ← p1 ← … ← p<n-1>, so the last task sits at level n.
	 *
	 * @param int $calendarId calendar ID
	 * @param string $prefix UID prefix of the chain
	 * @param int $levels number of tasks in the chain
	 * @return string UID of the deepest task
	 */
	public function chain(int $calendarId, string $prefix, int $levels): string {
		$parent = null;
		for ($i = 1; $i <= $levels; $i++) {
			$this->task($calendarId, $prefix . $i, $parent);
			$parent = $prefix . $i;
		}
		return (string)$parent;
	}

	/**
	 * Plants a raw object.
	 *
	 * @param int $calendarId calendar ID
	 * @param string $uid UID the lookups find it by
	 * @param string $component component it is stored as
	 * @param string $data stored data
	 * @return self this store
	 */
	public function raw(int $calendarId, string $uid, string $component, string $data): self {
		$this->objects[$calendarId][$uid] = ['component' => $component, 'deleted' => false, 'data' => $data];
		return $this;
	}

	/** {@inheritDoc} */
	public function calendarsForPrincipal(string $principalUri): array {
		return [];
	}

	/** {@inheritDoc} */
	public function eventUrisInRange(int $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array {
		return [];
	}

	/** {@inheritDoc} */
	public function objects(int $calendarId, array $uris): array {
		$rows = [];
		foreach ($uris as $uri) {
			$row = $this->object($calendarId, $uri);
			if ($row !== null) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/** {@inheritDoc} */
	public function object(int $calendarId, string $uri): ?array {
		$uid = basename($uri, '.ics');
		$object = $this->objects[$calendarId][$uid] ?? null;
		return $object === null ? null : ['id' => 1, 'uri' => $uid . '.ics', 'etag' => '"v1"', 'data' => $object['data'], 'deleted' => $object['deleted']];
	}

	/** {@inheritDoc} */
	public function objectByUid(int $calendarId, string $uid, string $component): ?array {
		$this->lookups++;
		$object = $this->objects[$calendarId][$uid] ?? null;
		return $object === null || $object['component'] !== $component ? null : $this->object($calendarId, $uid . '.ics');
	}

	/** {@inheritDoc} */
	public function sharesOf(int $calendarId): array {
		return [];
	}

	/** {@inheritDoc} */
	public function uris(int $calendarId): array {
		$uris = [];
		foreach ($this->objects[$calendarId] ?? [] as $uid => $object) {
			if ($object['component'] === 'VTODO' && !$object['deleted']) {
				$uris[] = $uid . '.ics';
			}
		}
		return $uris;
	}
}
