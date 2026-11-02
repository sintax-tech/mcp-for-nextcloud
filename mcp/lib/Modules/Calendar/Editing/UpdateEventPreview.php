<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Editing;

use OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;

/**
 * The plan of `calendar_update_event`: the change of an event, as it would happen now, with nothing written.
 *
 * A failure is not mapped here: it leaves as it is, exactly as the plan of a write always did.
 */
final class UpdateEventPreview {
	/**
	 * @param CalendarDraftApproval $approval the confirmation every Calendar write goes through
	 * @param UpdateEventDraft $drafts the preparation of calendar_update_event
	 */
	public function __construct(
		private CalendarDraftApproval $approval,
		private UpdateEventDraft $drafts,
	) {
	}

	/**
	 * @param string $userId authenticated user
	 * @param array<string, mixed> $arguments validated tool arguments, without confirm
	 * @return array<string, mixed> the plan, as the structured content of a non-error result
	 * @throws CalendarException when the write would be refused (ACL, calendar or event not found, changed meanwhile)
	 * @throws CalendarArgumentException on an argument the schema cannot validate (-32602)
	 */
	public function plan(string $userId, array $arguments): array {
		return $this->approval->preview('calendar_update_event', $this->drafts, $arguments, $userId);
	}
}
