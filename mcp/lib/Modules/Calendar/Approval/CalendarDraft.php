<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Approval;

use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;

/**
 * The preparation of one Calendar write. The public writes use prepare() through {@see CalendarDraftApproval}; the
 * selftest invokes execute() directly, under its own controlled script.
 */
interface CalendarDraft {
	/**
	 * Prepares the write and dispatches it at once, with no plan and no approval: the direct path of the selftest.
	 *
	 * @param array<string, mixed> $arguments tool arguments
	 * @param string $userId authenticated UID
	 * @return array{content: list<array{type:string, text:string}>, isError?: bool} MCP result
	 * @throws CalendarException on a user-facing failure
	 * @throws CalendarArgumentException on an argument the schema cannot validate
	 */
	public function execute(array $arguments, string $userId): array;

	/**
	 * Validates the write and builds its proposal without dispatching, allowing the registry to present a plan first.
	 *
	 * @param array<string, mixed> $arguments raw tool arguments
	 * @param string $userId authenticated user id
	 * @return PreparedCalendarWrite|array{content: list<array{type:string, text:string}>} the prepared write, or a SharedGuard refusal result
	 */
	public function prepare(array $arguments, string $userId): PreparedCalendarWrite|array;
}
