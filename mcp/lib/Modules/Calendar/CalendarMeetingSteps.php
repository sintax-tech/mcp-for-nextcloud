<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventHandler;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventPreview;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Calendar\Tools\CreateEvent;
use OCA\Mcp\Platform\Meetings\MeetingCalendar;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The Calendar step of a meeting, through the very work classes of calendar_create_event.
 *
 * The step is checked as the registry checks calendar_create_event (grant, Guests and the Calendar app by
 * {@see ToolAccess}, then the published schema); the work classes check the resource scope, the native ACL and the
 * calendar of somebody else as they always do, and write through the CalDAV pipeline with its scheduling. Before the
 * write the plan runs again, so a refusal known before the write is reported as failed and only a failure of the
 * write itself, or of its read-back, as uncertain.
 */
final class CalendarMeetingSteps implements MeetingCalendar {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param CreateEventPreview $plans the plan of calendar_create_event
	 * @param CreateEventHandler $creates the effect of calendar_create_event
	 * @param LoggerInterface $logger the class and code of a write that ended in doubt, never its message
	 */
	public function __construct(
		private ToolAccess $access,
		private CreateEventPreview $plans,
		private CreateEventHandler $creates,
		private LoggerInterface $logger,
	) {
	}

	/** {@inheritDoc} */
	public function planEvent(string $userId, array $event): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(CreateEvent::definition()->name));
		}
		return $this->plans->plan($userId, ArgumentValidator::validate(CreateEvent::definition()->inputSchema, $event));
	}

	/** {@inheritDoc} */
	public function createEvent(string $userId, array $event): WorkflowStep {
		if (!$this->allowed($userId)) {
			return WorkflowStep::failed(WorkflowMessages::notAllowed(CreateEvent::definition()->name));
		}
		$arguments = ArgumentValidator::validate(CreateEvent::definition()->inputSchema, $event);
		try {
			$plan = $this->plans->plan($userId, $arguments);
		} catch (ToolFailure $refused) {
			return WorkflowStep::failed($refused->getMessage());
		}
		// The handler refuses this too; checked here only so the refusal reads as failed and not as uncertain.
		if (($plan['shared'] ?? []) !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
			return WorkflowStep::failed(CalendarMessages::approvalShared());
		}
		try {
			$result = $this->creates->handle($userId, $arguments);
		} catch (InvalidArgumentException|PolicyUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error('MCP meeting event failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
			return WorkflowStep::uncertain(WorkflowMessages::uncertain());
		}
		$created = json_decode($result['content'][0]['text'] ?? '', true);
		$uid = is_array($created) && is_string($created['uid'] ?? null) ? $created['uid'] : null;
		if (($result['isError'] ?? false) === true || $uid === null || !isset($created['etag'])) {
			// The plan passed a moment ago: a failure now may come after the pipeline stored the event.
			$message = is_array($created) ? WorkflowMessages::uncertain() : (string)($result['content'][0]['text'] ?? WorkflowMessages::uncertain());
			return WorkflowStep::uncertain($message, $uid === null ? [] : ['uid' => $uid]);
		}
		return WorkflowStep::applied(['uid' => $uid], $created);
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call calendar_create_event now
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(CreateEvent::definition()->toArray(), $userId);
	}
}
