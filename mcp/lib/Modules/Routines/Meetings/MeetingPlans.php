<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\Meetings\MeetingCalendar;
use OCA\Mcp\Platform\Meetings\MeetingTalk;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;

/**
 * The three plans of a meeting, read through the Talk and Calendar ports and never written. The plan of the tool shows
 * them; the confirmed call reads them again before the room exists, so a step the actor may no longer run (a grant, an
 * app, an ACL, a calendar of somebody else) refuses the whole meeting instead of leaving a room behind.
 */
final class MeetingPlans {
	/**
	 * @param MeetingTalk $talk the room and agenda steps, implemented by the Talk module
	 * @param MeetingCalendar $calendar the event step, implemented by the Calendar module
	 */
	public function __construct(
		private MeetingTalk $talk,
		private MeetingCalendar $calendar,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return array{room: array<string, mixed>, agenda: array<string, mixed>, event: array<string, mixed>} the plan of each step
	 * @throws ToolFailure when a step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function read(string $userId, array $arguments): array {
		return array_map(static fn (\Closure $plan): array => $plan(), $this->steps($userId, $arguments));
	}

	/**
	 * The preflight of the confirmed call: every step planned again, in order, before anything is written.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return array{step: string, message: string}|null the first step that would be refused and why; null when all pass
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function firstRefusal(string $userId, array $arguments): ?array {
		foreach ($this->steps($userId, $arguments) as $step => $plan) {
			try {
				$planned = $plan();
			} catch (ToolFailure $refused) {
				return ['step' => $step, 'message' => $refused->getMessage()];
			}
		}
		return self::unconfirmedShared(['event' => $planned], $arguments)
			? ['step' => 'event', 'message' => RoutinesMessages::sharedNeeded()]
			: null;
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return array{room: \Closure(): array<string, mixed>, agenda: \Closure(): array<string, mixed>, event: \Closure(): array<string, mixed>} the plan of each step, in order, not yet read
	 */
	private function steps(string $userId, array $arguments): array {
		return [
			'room' => fn (): array => $this->talk->planRoom($userId, MeetingRequest::title($arguments), MeetingRequest::participants($arguments)),
			'agenda' => fn (): array => $this->talk->planAgenda($userId, MeetingRequest::agenda($arguments)),
			'event' => fn (): array => $this->calendar->planEvent($userId, MeetingRequest::event($arguments, null)),
		];
	}

	/**
	 * @param array{event: array<string, mixed>} $plans the plans of {@see self::read()}
	 * @param array<string, mixed> $arguments validated arguments of routines_create_meeting
	 * @return bool whether the event goes to a calendar of somebody else without confirm_shared: true
	 */
	public static function unconfirmedShared(array $plans, array $arguments): bool {
		return ($plans['event']['shared'] ?? []) !== [] && ($arguments['confirm_shared'] ?? false) !== true;
	}
}
