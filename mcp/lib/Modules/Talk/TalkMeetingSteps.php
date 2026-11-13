<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Talk\Groups\CreateGroupHandler;
use OCA\Mcp\Modules\Talk\Groups\CreateGroupPreview;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Replying\ReplyHandler;
use OCA\Mcp\Modules\Talk\Replying\ReplyPreview;
use OCA\Mcp\Modules\Talk\Tools\CreateGroup;
use OCA\Mcp\Modules\Talk\Tools\Reply;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Platform\Meetings\MeetingMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use OCA\Mcp\Platform\Meetings\MeetingTalk;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;

/**
 * The Talk steps of a meeting, through the very work classes of talk_create_group and talk_reply.
 *
 * Each step is checked as the registry checks the original tool (grant, Guests and the Talk app by
 * {@see ToolAccess}, then the published schema), and the work classes check participation, permissions, lobby and
 * reachability of the guests as they always do. Before the agenda is posted its own plan runs again, so a refusal
 * known before sending is reported as failed and only a failure of the send itself as uncertain.
 */
final class TalkMeetingSteps implements MeetingTalk {
	/** Talk's own route to a conversation, the one its notifications link to (spreed 24/25 Notifier.php:246). */
	private const ROOM_ROUTE = 'spreed.Page.showCall';

	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param CreateGroupPreview $roomPlans the plan of talk_create_group
	 * @param CreateGroupHandler $rooms the effect of talk_create_group
	 * @param ReplyPreview $agendaPlans the plan of talk_reply
	 * @param ReplyHandler $agendas the effect of talk_reply
	 * @param IURLGenerator $urls the absolute URL of the new room on this instance
	 * @param LoggerInterface $logger the class and code of a room link that could not be built, never its message
	 */
	public function __construct(
		private ToolAccess $access,
		private CreateGroupPreview $roomPlans,
		private CreateGroupHandler $rooms,
		private ReplyPreview $agendaPlans,
		private ReplyHandler $agendas,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	/** {@inheritDoc} */
	public function planRoom(string $userId, string $name, array $participants): array {
		$this->assertAllowed(CreateGroup::definition(), $userId);
		return $this->roomPlans->plan(self::validated(CreateGroup::definition(), ['name' => $name, 'participants' => $participants]), $userId);
	}

	/** {@inheritDoc} */
	public function createRoom(string $userId, string $name, array $participants): WorkflowStep {
		if (!$this->allowed(CreateGroup::definition(), $userId)) {
			return WorkflowStep::failed(WorkflowMessages::notAllowed(CreateGroup::definition()->name));
		}
		$result = $this->rooms->handle(self::validated(CreateGroup::definition(), ['name' => $name, 'participants' => $participants]), $userId);
		if (($result['isError'] ?? false) === true) {
			// GroupCreator resolves every guest and service first and reports a room that exists as a result, so an
			// error here means no room was created.
			return WorkflowStep::failed(self::text($result));
		}
		$room = self::payload($result);
		if (!is_string($room['conversation_token'] ?? null)) {
			return WorkflowStep::uncertain(WorkflowMessages::uncertain());
		}
		$token = $room['conversation_token'];
		$ids = ['conversation_token' => $token];
		$room['url'] = $this->roomUrl($token);
		// The room exists from here on: nothing below may lose its token or invite a second createRoom.
		$note = $room['url'] === null ? MeetingMessages::roomLinkMissing() : null;
		if (($room['invitations_failed'] ?? []) !== []) {
			return WorkflowStep::partial($ids, $room, $note === null ? Messages::invitationFailed() : Messages::invitationFailed() . ' ' . $note);
		}
		return WorkflowStep::applied($ids, $room, $note);
	}

	/** {@inheritDoc} */
	public function planAgenda(string $userId, string $message): array {
		$this->assertAllowed(Reply::definition(), $userId);
		$schema = Reply::definition()->inputSchema;
		$validated = ArgumentValidator::validate(
			['type' => 'object', 'properties' => ['message' => $schema['properties']['message']], 'required' => ['message'], 'additionalProperties' => false],
			['message' => $message],
		);
		// The text talk_reply would store; the room it goes to does not exist yet, so the plan names none.
		return ['action' => Reply::definition()->name, 'conversation' => null, 'draft' => ['message' => ConversationWriter::normalizeMessage($validated['message'])]];
	}

	/** {@inheritDoc} */
	public function postAgenda(string $userId, string $token, string $message): WorkflowStep {
		if (!$this->allowed(Reply::definition(), $userId)) {
			return WorkflowStep::failed(WorkflowMessages::notAllowed(Reply::definition()->name));
		}
		$arguments = self::validated(Reply::definition(), ['conversation_token' => $token, 'message' => $message]);
		try {
			$this->agendaPlans->plan($arguments, $userId);
		} catch (ToolFailure $refused) {
			return WorkflowStep::failed($refused->getMessage());
		}
		$result = $this->agendas->handle($arguments, $userId);
		if (($result['isError'] ?? false) === true) {
			// The plan passed a moment ago: a failure now may come after Talk stored the message.
			return WorkflowStep::uncertain(self::text($result), ['conversation_token' => $token]);
		}
		$sent = self::payload($result);
		if (!is_int($sent['messageId'] ?? null)) {
			return WorkflowStep::uncertain(WorkflowMessages::uncertain(), ['conversation_token' => $token]);
		}
		return WorkflowStep::applied(['conversation_token' => $token, 'messageId' => $sent['messageId']], $sent);
	}

	/**
	 * @param string $token the token of a room that already exists
	 * @return string|null the absolute URL of the room on this instance; null when it cannot be built, never a failure
	 */
	private function roomUrl(string $token): ?string {
		try {
			return $this->urls->linkToRouteAbsolute(self::ROOM_ROUTE, ['token' => $token]);
		} catch (Throwable $e) {
			$this->logger->warning('MCP meeting room link failed: {exception_detail}', ['app' => 'mcp', 'exception_detail' => ToolRegistry::loggable($e)]);
			return null;
		}
	}

	/**
	 * @param ToolDefinition $definition the original tool
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call the original tool now
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(ToolDefinition $definition, string $userId): bool {
		return $this->access->allowed($definition->toArray(), $userId);
	}

	/**
	 * @param ToolDefinition $definition the original tool
	 * @param string $userId the actor
	 * @throws ToolFailure when the registry would not let the actor call the original tool now
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when the grant cannot be read now
	 */
	private function assertAllowed(ToolDefinition $definition, string $userId): void {
		if (!$this->allowed($definition, $userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed($definition->name));
		}
	}

	/**
	 * @param ToolDefinition $definition the original tool
	 * @param array<string, mixed> $arguments the arguments of the step
	 * @return array<string, mixed> the arguments as the published schema of the original tool accepts them
	 * @throws \InvalidArgumentException naming the first argument the schema refuses
	 */
	private static function validated(ToolDefinition $definition, array $arguments): array {
		return ArgumentValidator::validate($definition->inputSchema, $arguments);
	}

	/**
	 * @param array{content: list<array{type: string, text: string}>} $result a success envelope of the module
	 * @return array<string, mixed> its JSON payload; empty when the text is not the JSON object of a result
	 */
	private static function payload(array $result): array {
		$payload = json_decode($result['content'][0]['text'] ?? '', true);
		return is_array($payload) ? $payload : [];
	}

	/**
	 * @param array{content: list<array{type: string, text: string}>} $result an error envelope of the module
	 * @return string the refusal, written for the user
	 */
	private static function text(array $result): string {
		return $result['content'][0]['text'];
	}
}
