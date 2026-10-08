<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

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

    public function __construct(
        private TalkServices $talkServices,
    ) {}

    /**
     * Resolves a conversation the user may read: one they take part in, behind its lobby only with the permission
     * that bypasses it, as Talk serves the history (`ChatController::receiveMessages`, `#[RequireModeratorOrNoLobby]`).
     *
     * @param string $userId Authenticated user
     * @param string $token Conversation token
     * @throws InvalidArgumentException When the token is malformed
     * @throws ConversationAccessException When the conversation is missing, the user is not a participant or the
     *     lobby keeps them out
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function resolveForReading(string $userId, string $token): Conversation {
        $conversation = $this->resolve($userId, $token);
        $constants = $this->talkServices->conversationConstants($userId);
        if ($this->lobbyKeepsOut($userId, $conversation, $constants)) {
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
        if ($this->lobbyKeepsOut($userId, $conversation, $constants)) {
            throw new ConversationAccessException(Messages::conversationNotWritable());
        }

        return $conversation;
    }

    /**
     * Whether the lobby keeps the caller out of a conversation already resolved, the question the listing asks for
     * every room it shows; see {@see self::lobbyKeepsOut()}.
     *
     * @param string $userId Authenticated user
     * @param Conversation $conversation Room and participant of the caller
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function keepsOutOfLobby(string $userId, Conversation $conversation): bool {
        return $this->lobbyKeepsOut($userId, $conversation, $this->talkServices->conversationConstants($userId));
    }

    /**
     * Turns a conversation token into the room and the caller's participant, nothing else checked.
     *
     * Every resolution error becomes the same answer, see the class description.
     *
     * @param string $userId Authenticated user
     * @param string $token Conversation token
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

        return new Conversation($room, $participant);
    }

    /**
     * Whether the lobby keeps the caller out, as Talk's `RequireModeratorOrNoLobby` decides: the lobby is on and the
     * participant lacks the bypass, which moderators always hold. A lobby whose timer has passed is opened first.
     *
     * @param string $userId Authenticated user
     * @param Conversation $conversation Room and participant of the caller
     * @param array{lobbyNone:int, lobbyIgnorePermission:int} $constants Talk constants of {@see TalkServices::conversationConstants()}
     */
    private function lobbyKeepsOut(string $userId, Conversation $conversation, array $constants): bool {
        $room = $conversation->room;
        if ($room->getLobbyState() === $constants['lobbyNone']) {
            return false;
        }
        if (((int)$conversation->participant->getPermissions() & $constants['lobbyIgnorePermission']) !== 0) {
            return false;
        }
        $this->expireLobbyTimer($userId, $room);

        return $room->getLobbyState() !== $constants['lobbyNone'];
    }

    /**
     * Opens a lobby whose timer has passed, as Talk does before it reads the lobby state.
     *
     * Up to Talk 23 Room::getLobbyState() does it by itself. Talk 24 (Nextcloud 34) moved it to
     * RoomService::validateLobbyTimer(), which its controllers call first; without the call a webinar past its start
     * time would stay closed here. The check fails closed: a missing method, a changed signature (a TypeError or
     * ArgumentCountError is caught like any other Throwable) or a database error leaves the lobby as Talk reports it,
     * closed, so the worst case is a refusal, never an opened lobby.
     *
     * @param string $userId Authenticated user
     * @param object $room OCA\Talk\Room
     */
    private function expireLobbyTimer(string $userId, object $room): void {
        try {
            $roomService = $this->talkServices->roomService($userId);
            if (method_exists($roomService, 'validateLobbyTimer')) {
                $roomService->validateLobbyTimer($room);
            }
        } catch (Throwable) {
            // The lobby stays as getLobbyState() reports it.
        }
    }

    /**
     * @throws InvalidArgumentException When the token is malformed
     */
    private function assertValidToken(string $token): void {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException(Messages::invalidToken());
        }
    }
}
