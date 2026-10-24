<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Titles of the parent tasks a write plan names, so the person reads a task and not only a UID. A parent the
 * caller may not read (trashed, malformed, private or confidential in a shared calendar) gets no title: the plan
 * then shows its UID alone, which the task already carried.
 */
final class ParentTitles {
	/**
	 * Receives the store and the parser.
	 *
	 * @param CalendarStore $store read-only storage port
	 * @param TaskData $data parser and classifier of tasks
	 * @return void
	 */
	public function __construct(
		private CalendarStore $store,
		private TaskData $data,
	) {
	}

	/**
	 * The titles of the parents before and after a write.
	 *
	 * @param Calendar $calendar authorized calendar of the task
	 * @param string $userId authenticated user UID
	 * @param list<string|null> $uids parent UIDs before and after the write
	 * @return array<string, string> title by parent UID, only for the parents the caller may read
	 */
	public function of(Calendar $calendar, string $userId, array $uids): array {
		$titles = [];
		foreach (array_unique(array_filter($uids, static fn (?string $uid): bool => $uid !== null)) as $uid) {
			$row = $this->store->objectByUid($calendar->id, $uid, 'VTODO');
			if ($row === null) {
				continue;
			}
			try {
				$parsed = (new TaskRowReader($this->data))->readable($row, $calendar->ownedByOther($userId));
				$titles[$uid] = (string)$this->data->master($parsed)->SUMMARY;
			} catch (ToolFailure) {
				continue;
			}
		}
		return $titles;
	}
}
