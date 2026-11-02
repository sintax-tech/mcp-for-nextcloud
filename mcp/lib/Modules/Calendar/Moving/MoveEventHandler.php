<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Moving;

use OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarFailures;
use RuntimeException;

/**
 * The effect of `calendar_move_event`:
 * the move of an event to another calendar of the same owner, as the user confirmed it.
 *
 * The draft is prepared again, so every check runs at dispatch time; the write is refused when it touches a calendar
 * of somebody else without `confirm_shared: true`, and its failures are reported as a confirmed call reports them.
 */
final class MoveEventHandler {
	/**
	 * @param CalendarDraftApproval $approval the confirmation every Calendar write goes through
	 * @param MoveEventDraft $drafts the preparation of calendar_move_event
	 */
	public function __construct(
		private CalendarDraftApproval $approval,
		private MoveEventDraft $drafts,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated tool arguments, with confirm
	 * @return array<string, mixed> the MCP result of the write, or the error result of a refusal the user can read
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 * @throws RuntimeException on any other failure, which the registry reports generically
	 */
	public function handle(string $userId, array $arguments): array {
		return CalendarFailures::run(fn (): array => $this->approval->execute($this->drafts, $arguments, $userId));
	}
}
