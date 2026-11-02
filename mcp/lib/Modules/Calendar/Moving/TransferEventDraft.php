<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Moving;

use OCA\Mcp\Modules\Calendar\Moving\EventRelocator;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;

/**
 * calendar_transfer_event: moves an event to a calendar of another owner that the user can write.
 */
final class TransferEventDraft implements \OCA\Mcp\Modules\Calendar\Approval\CalendarDraft {
	/**
	 * @param EventRelocator $relocator shared move/transfer logic
	 */
	public function __construct(private EventRelocator $relocator) {}

	/**
	 * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string} $arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} {uid, etag, from, to}
	 * @throws \OCA\Mcp\Modules\Calendar\Failures\CalendarException when the transfer is not allowed or conflicts
	 */
	public function execute(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		return is_array($prepared) ? $prepared : SchemaBuilder::result($prepared->dispatch());
	}

	/**
	 * Builds the transfer proposal without dispatching it, so the registry can show the plan first.
	 *
	 * @param array<string, mixed> $arguments raw tool arguments
	 * @param string $userId authenticated user id
	 * @return \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
	 */
	public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array {
		return $this->relocator->prepare($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, true);
	}
}
