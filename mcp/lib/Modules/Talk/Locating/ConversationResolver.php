<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Locating;
use OCA\Mcp\Modules\Talk\{Failures\ConversationAccessException, Presentation\Messages, Integration\TalkServices, Integration\UnreadRoomView, Failures\TalkUnavailableException};
use InvalidArgumentException;
use Throwable;

/**
 * Turns a conversation token into a room plus the caller's participant, refusing anything the caller may not do.
 *
 * Every resolution error becomes the same answer, so a missing room, a room the caller is not in and a room that
 * forbids writing are indistinguishable from outside: the module must not answer "exists but forbidden".
 */
class ConversationResolver {
	/**
	 * Talk tokens are lowercase alphanumerics; anything else is a malformed argument, not a missing conversation.
	 */
	private const TOKEN_PATTERN = '/^[a-z0-9]{4,30}$/';

	/**
	 * Keeps the lazy native Talk boundary for conversation access checks.
	 * @param TalkServices $talkServices lazy native Talk services and per-user enablement
	 * @param UnreadRoomView $rooms lobby and display name decided from values already loaded, never written back
	 */
	public function __construct(
		private TalkServices $talkServices,
		private UnreadRoomView $rooms,
	) {}

	/**
	 * Resolves a conversation the user may read: one they take part in, behind its lobby only with the permission
	 * that bypasses it, as Talk serves the history (`ChatController::receiveMessages`, `#[RequireModeratorOrNoLobby]`).
	 *
	 * @param string $userId Authenticated user
	 * @param string $token Conversation token
	 * @return Conversation room and participant after the existing read checks
	 * @throws InvalidArgumentException When the token is malformed
	 *     lobby keeps them out
	 * @throws ConversationAccessException When the conversation is missing, the user is not a participant or the
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	public function resolveForReading(string $userId, string $token): Conversation {
		$conversation = $this->resolve($userId, $token);
		$constants = $this->talkServices->conversationConstants($userId);
		if ($this->lobbyKeepsOut($conversation, $constants)) {
			throw new ConversationAccessException(Messages::conversationNotFound());
		}

		return $conversation;
	}

	/**
	 * Resolves a conversation the user may write in: no read-only rooms, no changelog and a participant holding
	 * the chat permission, with the lobby bypassed only by the permission that allows it.
	 *
	 * @param string $userId Authenticated user
	 * @param string $token Conversation token
	 * @return Conversation room and participant after the existing write checks
	 * @throws InvalidArgumentException When the token is malformed
	 * @throws ConversationAccessException When the conversation is missing or the user may not write in it
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	public function resolveForWriting(string $userId, string $token): Conversation {
		$conversation = $this->resolve($userId, $token);
		$constants = $this->talkServices->conversationConstants($userId);
		$room = $conversation->room;
		$participant = $conversation->participant;

		if ($room->isFederatedConversation()
			|| $room->getReadOnly() === $constants['readOnly']
			|| $room->getType() === $constants['changelogType']) {
			throw new ConversationAccessException(Messages::conversationNotWritable());
		}

		$permissions = (int)$participant->getPermissions();
		if (($permissions & $constants['chatPermission']) === 0) {
			throw new ConversationAccessException(Messages::conversationNotWritable());
		}
		if ($this->lobbyKeepsOut($conversation, $constants)) {
			throw new ConversationAccessException(Messages::conversationNotWritable());
		}

		return $conversation;
	}

	/**
	 * Turns a conversation token into the room and the caller's participant, nothing else checked.
	 *
	 * Every resolution error becomes the same answer, see the class description.
	 *
	 * @param string $userId Authenticated user
	 * @param string $token Conversation token
	 * @return Conversation native room and caller participant before operation-specific checks
	 * @throws InvalidArgumentException When the token is malformed
	 * @throws ConversationAccessException When the conversation is missing or the user is not a participant
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	private function resolve(string $userId, string $token): Conversation {
		$this->assertValidToken($token);

		try {
			$manager = $this->talkServices->manager($userId);
			$participantService = $this->talkServices->participantService($userId);
			$room = $manager->getRoomForUserByToken($token, $userId);
			$participant = $participantService->getParticipant($room, $userId, false);
		} catch (TalkUnavailableException $e) {
			// The app being unavailable is not the same answer as a missing conversation: it must reach the caller
			// so the module says why it cannot try at all.
			throw $e;
		} catch (Throwable $e) {
			throw new ConversationAccessException(Messages::conversationNotFound(), $e);
		}
		$changelog = $this->talkServices->conversationConstants($userId)['changelogType'];

		return new Conversation($room, $participant, $this->rooms->displayName($room, $userId, $changelog, $participantService));
	}

	/**
	 * Whether the lobby keeps the caller out, decided in memory from the values already loaded on the room.
	 *
	 * Talk's `RequireModeratorOrNoLobby` opens an elapsed lobby before it answers ({@see self::openElapsedLobby()}).
	 * A read and a plan ask the same question and stop there: a timer that is already in the past counts as open
	 * for this answer only, and nothing is written. A lobby that cannot be read counts as closed.
	 *
	 * @param Conversation $conversation Room and participant of the caller
	 * @param array{lobbyNone:int, lobbyIgnorePermission:int} $constants Talk constants of {@see TalkServices::conversationConstants()}
	 * @return bool whether the current lobby state excludes this participant
	 */
	private function lobbyKeepsOut(Conversation $conversation, array $constants): bool {
		return $this->rooms->barred(
			$conversation->room,
			$conversation->participant,
			$constants['lobbyNone'],
			$constants['lobbyIgnorePermission'],
		);
	}

	/**
	 * Opens a lobby whose timer has passed, and only on a confirmed write that is about to change the room.
	 *
	 * Talk 24 (Nextcloud 34) moved that maintenance out of `Room::getLobbyState()` into
	 * `RoomService::validateLobbyTimer()`, and `InjectionMiddleware::checkLobbyState()` calls it before a controller
	 * acts, then refuses while the stored state is not "no lobby". This does the same, in that order. A missing
	 * method is the older Talk, whose own getter still opens the lobby. A failure or a lobby that stays closed
	 * refuses the write: the worst case is no change.
	 *
	 * @param string $userId Authenticated user
	 * @param Conversation $conversation Room already accepted for writing
	 * @return void
	 * @throws ConversationAccessException When the lobby cannot be opened and still bars the caller
	 * @throws TalkUnavailableException When spreed is unavailable
	 */
	public function openElapsedLobby(string $userId, Conversation $conversation): void {
		$constants = $this->talkServices->conversationConstants($userId);
		$room = $conversation->room;
		if ($room->getLobbyState() === $constants['lobbyNone']) {
			return;
		}
		if (((int)$conversation->participant->getPermissions() & $constants['lobbyIgnorePermission']) !== 0) {
			return;
		}

		try {
			$roomService = $this->talkServices->roomService($userId);
			if (method_exists($roomService, 'validateLobbyTimer')) {
				$roomService->validateLobbyTimer($room);
			}
			$open = $room->getLobbyState() === $constants['lobbyNone'];
		} catch (TalkUnavailableException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new ConversationAccessException(Messages::conversationNotWritable(), $e);
		}

		if (!$open) {
			throw new ConversationAccessException(Messages::conversationNotWritable());
		}
	}

	/**
	 * @param string $token public conversation token
	 * @return void
	 * @throws InvalidArgumentException When the token is malformed
	 */
	private function assertValidToken(string $token): void {
		if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			throw new InvalidArgumentException(Messages::invalidToken());
		}
	}
}
