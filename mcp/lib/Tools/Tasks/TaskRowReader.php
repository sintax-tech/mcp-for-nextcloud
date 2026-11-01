<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component\VCalendar;

/**
 * Turns a stored task object into a parsed calendar only when the caller may see it.
 *
 * The single place that decides whether a trashed, malformed or protected task is exposed, so every tool
 * that reads task objects applies the same rule and a protected task stays indistinguishable from a
 * missing one.
 */
final class TaskRowReader {
	/**
	 * Receives the serializer that parses and classifies tasks.
	 *
	 * @param TaskData $data complete serialized DAV object
	 * @return void
	 */
	public function __construct(
		private TaskData $data,
	) {
	}

	/**
	 * Parses a live task and checks classification before exposing any fields.
	 *
	 * @param array<string, mixed> $row object returned by CalendarStore
	 * @param bool $shared whether the calendar belongs to another user
	 * @return VCalendar
	 * @throws ToolFailure when the object is trashed, malformed or protected in a share
	 */
	public function readable(array $row, bool $shared): VCalendar {
		if ($row['deleted']) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		$parsed = $this->data->parse($row['data']);
		if (!$this->data->visible($parsed, $shared)) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		return $parsed;
	}
}
