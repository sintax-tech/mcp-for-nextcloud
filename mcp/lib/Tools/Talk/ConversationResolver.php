<?php
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
     * Resolves a conversation the user takes part in. Enough to list its history.
     *
     * @param string $userId Authenticated user
     * @param string $token Conversation token
     * @throws InvalidArgumentException When the token is malformed
     * @throws ConversationAccessException When the conversation is missing or the user is not a participant
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function resolveForReading(string $userId, string $token): Conversation {
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
        $conversation = $this->resolveForReading($userId, $token);
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
        if ($room->getLobbyState() !== $constants['lobbyNone']
            && ($permissions & $constants['lobbyIgnorePermission']) === 0) {
            throw new ConversationAccessException(Messages::conversationNotWritable());
        }

        return $conversation;
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
