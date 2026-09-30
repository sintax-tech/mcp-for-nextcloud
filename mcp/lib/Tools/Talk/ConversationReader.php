<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use DateTimeInterface;
use InvalidArgumentException;
use OCP\Comments\IComment;
use Throwable;

/**
 * Read-only view of the conversations of the authenticated user, normalized into plain arrays.
 *
 * Nothing here marks anything as read: reading must not change unread counters, notifications or room activity.
 */
class ConversationReader {
    public const MAX_MESSAGES = 200;

    /** Default amount of messages when the caller does not ask for a number, matching the module schema. */
    public const DEFAULT_MESSAGES = 50;

    public function __construct(
        private TalkServices $talkServices,
        private ConversationResolver $resolver,
        private ActorNames $actorNames,
    ) {}

    /**
     * The conversations the user takes part in, most recently active first.
     *
     * @param string $userId Authenticated user
     * @return list<array{token:string, displayName:string, type:int, participantType:int, unreadMessages:int, archived:bool, lastActivity:int|null}>
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function listConversations(string $userId): array {
        $manager = $this->talkServices->manager($userId);
        $participantService = $this->talkServices->participantService($userId);

        $conversations = [];
        foreach ($manager->getRoomsForUser($userId) as $room) {
            try {
                $participant = $participantService->getParticipant($room, $userId, false);
            } catch (Throwable) {
                // A room without a readable participant is one this user cannot describe: skip it instead of
                // failing the whole listing.
                continue;
            }

            $attendee = $participant->getAttendee();
            $lastActivity = $room->getLastActivity();
            $conversations[] = [
                'token' => $room->getToken(),
                'displayName' => $room->getDisplayName($userId, false),
                'type' => $room->getType(),
                'participantType' => $attendee->getParticipantType(),
                'unreadMessages' => $attendee->getUnreadMessages(),
                'archived' => $attendee->isArchived(),
                'lastActivity' => $lastActivity instanceof DateTimeInterface ? $lastActivity->getTimestamp() : null,
            ];
        }

        usort(
            $conversations,
            static fn (array $a, array $b): int => ($b['lastActivity'] ?? 0) <=> ($a['lastActivity'] ?? 0),
        );

        return $conversations;
    }

    /**
     * The last messages of a conversation, oldest first, in the shape the module documents.
     *
     * @param string $userId Authenticated user
     * @param string $token Conversation token
     * @param int $limit How many messages to return, between 1 and self::MAX_MESSAGES
     * @return list<array{id:string, type:string, text:?string, actorType:string, actorId:string, actorDisplayName:string, timestamp:int, parentId:?string, attachmentId:?int}>
     * @throws InvalidArgumentException When the limit is out of range
     * @throws ConversationAccessException When the conversation is missing or the user is not a participant
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function readMessages(string $userId, string $token, int $limit): array {
        if ($limit < 1 || $limit > self::MAX_MESSAGES) {
            throw new InvalidArgumentException(Messages::invalidLimit());
        }

        $conversation = $this->resolver->resolveForReading($userId, $token);
        $chatManager = $this->talkServices->chatManager($userId);

        $messages = [];
        foreach ($chatManager->getHistory($conversation->room, -$limit, $limit, false) as $comment) {
            $messages[] = $this->normalizeMessage($comment);
        }

        return $messages;
    }

    /**
     * A chat comment becomes one module message: a plain comment keeps its text, while a Talk system envelope keeps
     * its type and contributes the share id so the model can quote the attachment with talk_quote_file.
     *
     * @param IComment $comment Chat comment as stored by Talk
     * @return array{id:string, type:string, text:?string, actorType:string, actorId:string, actorDisplayName:string, timestamp:int, parentId:?string, attachmentId:?int}
     */
    private function normalizeMessage(IComment $comment): array {
        $raw = (string)$comment->getMessage();
        $type = (string)$comment->getVerb();
        $text = $raw;
        $attachmentId = null;

        $envelope = json_decode(ltrim($raw), true);
        if (is_array($envelope) && isset($envelope['message']) && is_string($envelope['message'])) {
            $type = $envelope['message'];
            $text = null;
            $share = $envelope['parameters']['share'] ?? null;
            if (is_int($share) || (is_string($share) && ctype_digit($share))) {
                $attachmentId = (int)$share;
            }
        }

        $actorId = (string)$comment->getActorId();
        $parentId = (string)$comment->getParentId();
        $created = $comment->getCreationDateTime();

        return [
            'id' => (string)$comment->getId(),
            'type' => $type,
            'text' => $text,
            'actorType' => (string)$comment->getActorType(),
            'actorId' => $actorId,
            'actorDisplayName' => $this->actorNames->displayName($actorId),
            'timestamp' => $created instanceof DateTimeInterface ? $created->getTimestamp() : 0,
            'parentId' => ($parentId === '' || $parentId === '0') ? null : $parentId,
            'attachmentId' => $attachmentId,
        ];
    }
}
