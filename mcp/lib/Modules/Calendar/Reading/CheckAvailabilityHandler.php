<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Presentation\AvailabilityMessages;
use Throwable;

/** One bounded read, never a meeting search, calendar enumeration, reservation or invitation. */
final class CheckAvailabilityHandler {
	/**
	 * @param GrantPolicy $policy current operation's scope snapshot, never a new independent policy read
	 * @param AvailabilityWindow $window strict instant validation
	 * @param AvailabilityParticipants $participants exact account resolution
	 * @param CalendarAvailability $native lazy official free/busy API
	 */
	public function __construct(
		private GrantPolicy $policy,
		private AvailabilityWindow $window,
		private AvailabilityParticipants $participants,
		private CalendarAvailability $native,
	) {}

	/**
	 * @param string $userId authenticated organizer
	 * @param array<string, mixed> $arguments one explicit window and up to 20 local UIDs
	 * @return array<string, mixed> safe refusal, or status per input identifier without account or event details
	 */
	public function handle(string $userId, array $arguments): array {
		// This API has no collection selector. Even an empty restriction must refuse; unreadable policy propagates.
		if ($this->policy->scopeItems($userId, 'calendar') !== null) {
			return ToolResult::error(AvailabilityMessages::restricted());
		}
		[$start, $end, $zone] = $this->window->parse($arguments);
		$people = $this->participants->resolve($arguments['participants'] ?? null);
		$organizer = $this->participants->organizer($userId);
		try {
			$verdicts = $this->native->check($start, $end, $organizer, array_values(array_unique(array_filter($people))));
		} catch (Throwable) {
			// No native message, resolved email, appointment content or partial success leaks on failure.
			$verdicts = [];
		}
		$rows = [];
		foreach ($people as $id => $email) {
			$value = $email === null ? null : ($verdicts[$email] ?? null);
			$rows[] = ['participant' => (string)$id, 'status' => $value === null ? 'unknown' : ($value ? 'free' : 'busy')];
		}
		return ToolResult::json([
			'start' => $start->setTimezone($zone)->format(DATE_ATOM),
			'end' => $end->setTimezone($zone)->format(DATE_ATOM),
			'timezone' => $zone->getName(),
			'participants' => $rows,
			'complete' => !in_array('unknown', array_column($rows, 'status'), true),
		]);
	}
}
