<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\OutputReferenceFilter;
use OCA\Mcp\Tools\ToolFailure;
use Sabre\VObject\Component\VCalendar;

/**
 * Finds a task object in an authorized calendar, parses it only when the caller may see it, and turns it into the
 * fields a tool shows. Moved out of TasksModule unchanged, so the task handlers and the write flow share it.
 */
final class TaskObjects {
	/**
	 * Receives the store, the serializer and the output rule for Files references.
	 *
	 * @param CalendarStore $store read-only storage port
	 * @param TaskData $data complete serialized DAV object
	 * @param OutputReferenceFilter $references output rule for ATTACH references to Files nodes
	 * @return void
	 */
	public function __construct(
		private CalendarStore $store,
		private TaskData $data,
		private OutputReferenceFilter $references,
	) {
	}

	/**
	 * Reads a task object by UID within the already-authorized calendar.
	 *
	 * @param int $id authorized calendar ID
	 * @param string $uid task UID
	 * @return array<string, mixed> the stored object
	 * @throws ToolFailure when no task object has that UID
	 */
	public function row(int $id, string $uid): array {
		return $this->store->objectByUid($id, $uid, 'VTODO') ?? throw new ToolFailure(CommonMessages::notFound());
	}

	/**
	 * Parses a live task and checks classification before exposing any fields.
	 *
	 * @param array<string, mixed> $row object returned by CalendarStore
	 * @param bool $shared whether the calendar belongs to another user
	 * @return VCalendar the parsed task
	 * @throws ToolFailure when the object is trashed, malformed or protected in a share
	 */
	public function readable(array $row, bool $shared): VCalendar {
		return (new TaskRowReader($this->data))->readable($row, $shared);
	}

	/**
	 * The task fields as a tool shows them: the serialized calendar is the output copy, without the ATTACH whose
	 * file the user may not reach, never the object a write sends to DAV.
	 *
	 * @param string $userId authenticated user UID
	 * @param VCalendar $calendar task calendar as read from or prepared for DAV, left unchanged
	 * @return array<string, mixed> the task fields
	 * @throws ToolFailure when task dates or identity cannot be read safely
	 */
	public function output(string $userId, VCalendar $calendar): array {
		return $this->data->item($this->references->outputCalendar($userId, $calendar));
	}
}
