<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Failures\CalendarFailures;

/** One bounded read, never a meeting search, calendar enumeration, reservation or invitation. */
final class CheckAvailabilityHandler {
	/**
	 * @param AvailabilityQuery $availability guarded native read
	 * @param AvailabilityWindow $window strict instant validation
	 */
	public function __construct(private AvailabilityQuery $availability, private AvailabilityWindow $window) {}

	/**
	 * @param string $userId authenticated organizer
	 * @param array<string, mixed> $arguments one explicit window and up to 20 local UIDs
	 * @return array<string, mixed> safe refusal, or status per input identifier without account or event details
	 */
	public function handle(string $userId, array $arguments): array {
		return CalendarFailures::run(function () use ($userId, $arguments): array {
			$this->availability->assertUnrestricted($userId);
			[$start, $end, $zone] = $this->window->parse($arguments);
			$audience = $this->availability->audience($userId, $arguments['participants'] ?? null);
			$rows = $this->availability->check($audience, $start, $end);
			return ToolResult::json([
				'start' => $start->setTimezone($zone)->format(DATE_ATOM),
				'end' => $end->setTimezone($zone)->format(DATE_ATOM),
				'timezone' => $zone->getName(),
				'participants' => $rows,
				'complete' => !in_array('unknown', array_column($rows, 'status'), true),
			]);
		});
	}
}
