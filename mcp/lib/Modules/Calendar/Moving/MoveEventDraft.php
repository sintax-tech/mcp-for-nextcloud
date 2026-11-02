<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Moving;

use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Moving\EventRelocator;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;

/**
 * calendar_move_event: moves an event between two writable calendars of the same owner.
 */
final class MoveEventDraft implements \OCA\Mcp\Modules\Calendar\Approval\CalendarDraft {
	/**
	 * @param ScopedCalendarAccess $access the calendars the tools may reach: native visibility and ACL, narrowed by the resource scope
	 * @param EventRelocator $relocator shared move/transfer logic
	 * @param SharedGuard $guard confirmation gate for calendars of somebody else
	 */
	public function __construct(
		private ScopedCalendarAccess $access,
		private EventRelocator $relocator,
		private SharedGuard $guard,
	) {}

	/**
	 * @param array{calendar: string, uid: string, targetCalendar: string, etag?: string, confirm_shared?: bool} $arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>} {uid, etag, from, to}
	 * @throws \OCA\Mcp\Modules\Calendar\Failures\CalendarException when the move is not allowed or conflicts
	 */
	public function execute(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		return is_array($prepared) ? $prepared : SchemaBuilder::result($prepared->dispatch());
	}

	/**
	 * Builds the move proposal without dispatching it, so the registry can show the plan first.
	 *
	 * @param array<string, mixed> $arguments raw tool arguments
	 * @param string $userId authenticated user id
	 * @return \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} prepared write or SharedGuard refusal result
	 */
	public function prepare(array $arguments, string $userId): \OCA\Mcp\Modules\Calendar\Approval\PreparedCalendarWrite|array {
		$source = $this->access->resolveWritable($userId, $arguments['calendar']);
		$target = $this->access->resolveWritable($userId, $arguments['targetCalendar']);
		// A move never crosses owners: when it would, the relocator still answers with the transfer
		// hint, and that answer needs no gate. Origin and destination are both gated, because
		// either side can be the shared calendar a valid move is about to touch.
		if ($source->ownerPrincipal === $target->ownerPrincipal) {
			$confirmation = $this->guard->confirm($source, $userId, $arguments)
				?? $this->guard->confirm($target, $userId, $arguments);
			if ($confirmation !== null) {
				return $confirmation;
			}
		}
		return $this->relocator->prepare($userId, $arguments['calendar'], $arguments['uid'], $arguments['targetCalendar'], $arguments['etag'] ?? null, false);
	}
}
