<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Modules\Routines\RoutineStepGuard;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use OCA\Mcp\Platform\Meetings\MeetingCalendar;
use OCA\Mcp\Platform\Meetings\MeetingTalk;
use Psr\Log\LoggerInterface;

/**
 * The confirmed routines_create_meeting: room, agenda and event, once each, in this order (D4).
 *
 * Every step is planned again first, without writing, so a step the actor may no longer run refuses the meeting before
 * the room exists. Then each port checks again in its own sink, and the meeting stops at the first step that is not
 * applied: what exists stays and is reported, nothing is retried, deleted or undone. An exception of a step after the
 * room exists is that step's outcome, never a lost receipt; before the room, a refusal or 503 leaves as it always did. The event is created last, with
 * the link of the room as its location; without a link it is not created.
 */
final class CreateMeetingHandler {
	/**
	 * @param MeetingPlans $plans the read-only preflight of the three steps
	 * @param MeetingTalk $talk the room and agenda steps, implemented by the Talk module
	 * @param MeetingCalendar $calendar the event step, implemented by the Calendar module
	 * @param LoggerInterface $logger the class and code of an exception a step threw, never its message ({@see RoutineStepGuard})
	 */
	public function __construct(
		private MeetingPlans $plans,
		private MeetingTalk $talk,
		private MeetingCalendar $calendar,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments validated arguments, with confirm
	 * @return array<string, mixed> the receipt as the result of the call; an error result only when nothing was written
	 * @throws \InvalidArgumentException on a value a step refuses, before anything is written
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when a grant cannot be read now
	 */
	public function handle(string $userId, array $arguments): array {
		$refusal = $this->plans->firstRefusal($userId, $arguments);
		if ($refusal !== null) {
			return self::result(MeetingReceipt::refused($refusal['step'], $refusal['message']), false);
		}
		$receipt = new MeetingReceipt();
		$room = $this->talk->createRoom($userId, MeetingRequest::title($arguments), MeetingRequest::participants($arguments));
		if (!$receipt->record('room', $room)) {
			return self::result($receipt->build(), $room->status !== WorkflowStep::FAILED);
		}
		$token = (string)$room->ids['conversation_token'];
		$ids = ['conversation_token' => $token];
		if (!$receipt->record('agenda', RoutineStepGuard::run($this->logger, $ids, fn (): WorkflowStep => $this->talk->postAgenda($userId, $token, MeetingRequest::agenda($arguments))))) {
			return self::result($receipt->build(), true);
		}
		$link = $room->result['url'] ?? null;
		if (!is_string($link)) {
			$receipt->skip('event', RoutinesMessages::eventWithoutLink());
			return self::result($receipt->build(), true);
		}
		$receipt->record('event', RoutineStepGuard::run($this->logger, [], fn (): WorkflowStep => $this->calendar->createEvent($userId, MeetingRequest::event($arguments, $link))));
		return self::result($receipt->build(), true);
	}

	/**
	 * A receipt after any write is a result, never an error a client could answer by calling again; only a meeting
	 * that wrote nothing is an error.
	 *
	 * @param array<string, mixed> $receipt the receipt of the meeting
	 * @param bool $written whether any step may have written
	 * @return array<string, mixed> the MCP result
	 */
	private static function result(array $receipt, bool $written): array {
		$result = ToolResult::json($receipt);
		return $written ? $result : $result + ['isError' => true];
	}
}
