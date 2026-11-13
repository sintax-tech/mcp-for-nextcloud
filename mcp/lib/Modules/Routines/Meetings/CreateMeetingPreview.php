<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;

/**
 * The plan of routines_create_meeting: the three steps in their order, with the data each one gets, and nothing written.
 * No room, token or link is invented: the event plan shows the field that will receive the link of the new room.
 */
final class CreateMeetingPreview {
	/** Keys of a step plan that belong to the confirmation of its own tool, not to this one. */
	private const OWN_CONFIRMATION = ['requiresConfirmation' => true, 'message' => true];

	/** @param MeetingPlans $plans the plans of the three steps */
	public function __construct(private MeetingPlans $plans) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments, without confirm
	 * @return array<string, mixed> the plan of the meeting
	 * @throws ToolFailure when a step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on a value a step refuses
	 */
	public function plan(string $userId, array $arguments): array {
		$plans = $this->plans->read($userId, $arguments);
		$notices = [RoutinesMessages::linkPending(), RoutinesMessages::stopsAtFirstFailure()];
		if (MeetingPlans::unconfirmedShared($plans, $arguments)) {
			$notices[] = RoutinesMessages::sharedNeeded();
		}
		return [
			'action' => 'routines_create_meeting',
			'message' => RoutinesMessages::planPrompt(),
			'steps' => [
				['step' => 'room', 'tool' => 'talk_create_group', 'plan' => array_diff_key($plans['room'], self::OWN_CONFIRMATION)],
				['step' => 'agenda', 'tool' => 'talk_reply', 'plan' => array_diff_key($plans['agenda'], self::OWN_CONFIRMATION)],
				['step' => 'event', 'tool' => 'calendar_create_event', 'plan' => array_diff_key($plans['event'], self::OWN_CONFIRMATION),
					'room_link' => ['field' => MeetingRequest::LINK_FIELD, 'value' => null]],
			],
			'notices' => $notices,
		];
	}
}
