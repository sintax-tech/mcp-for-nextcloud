<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use DateTimeZone;
use InvalidArgumentException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException as CommentNotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Throwable;

/**
 * Writes into a conversation the user may write in: a text reply, and the citation of an attachment already shared.
 *
 * The conversation arrives already validated by ConversationResolver, so this class never decides who may write.
 */
class ConversationWriter {
    public const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private TalkServices $talkServices,
        private ITimeFactory $timeFactory,
        private AttachmentMessage $attachmentMessage,
        private IShareManager $shareManager,
    ) {}

    /**
     * Sends a message, optionally quoting an existing message of the same conversation.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body
     * @param int|null $replyTo Id of the quoted message, when the reply quotes one
     * @return array{conversation_token:string, messageId:int}
     * @throws InvalidArgumentException When the message is blank, too long or the quoted id is not a positive integer
     * @throws ConversationAccessException When the quoted message does not exist in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function reply(Conversation $conversation, string $userId, string $message, ?int $replyTo = null): array {
        $body = $this->assertMessage($message);
        if ($replyTo !== null && $replyTo < 1) {
            throw new InvalidArgumentException(Messages::INVALID_IDENTIFIER);
        }

        $participantService = $this->talkServices->participantService($userId);
        $chatManager = $this->talkServices->chatManager($userId);
        $room = $conversation->room;

        $parent = null;
        if ($replyTo !== null) {
            try {
                $parent = $chatManager->getParentComment($room, (string)$replyTo);
            } catch (CommentNotFoundException $e) {
                // A missing id and an id from another conversation are the same answer.
                throw new ConversationAccessException(Messages::REPLY_TARGET_NOT_FOUND, $e);
            } catch (Throwable $e) {
                throw new ConversationAccessException(Messages::REPLY_TARGET_NOT_FOUND, $e);
            }
        }

        try {
            // A one to one room only has a real participant pair after Talk fills it in.
            $participantService->ensureOneToOneRoomIsFilled($room);
            $comment = $chatManager->sendMessage(
                $room,
                $conversation->participant,
                $this->actorType($userId),
                $userId,
                $body,
                $this->timeFactory->getDateTime('now', new DateTimeZone('UTC')),
                $parent,
            );
        } catch (TalkUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::MESSAGE_NOT_SENT, $e);
        }

        return [
            'conversation_token' => $room->getToken(),
            'messageId' => (int)$comment->getId(),
        ];
    }

    /**
     * Publishes a card quoting an attachment that is already in the conversation.
     *
     * No share is created here: the card is written directly as the Talk envelope, which is the call the Talk
     * listener would make, so nothing can post the same attachment twice.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param int $attachmentId Room share id of the attachment, as talk_read_messages reports it
     * @param string|null $caption Optional text rendered with the card
     * @return array{conversation_token:string, messageId:int, attachmentId:int}
     * @throws InvalidArgumentException When the caption is blank or too long
     * @throws ConversationAccessException When the message cannot be published
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function quoteAttachment(Conversation $conversation, string $userId, int $attachmentId, ?string $caption = null): array {
        $envelope = $this->attachmentMessage->build($attachmentId, $caption);
        $room = $conversation->room;
        $this->assertAttachmentIsInRoom($userId, $room->getToken(), $attachmentId);

        try {
            $chatManager = $this->talkServices->chatManager($userId);
            $comment = $chatManager->addSystemMessage(
                $room,
                $conversation->participant,
                $this->actorType($userId),
                $userId,
                $envelope,
                $this->timeFactory->getDateTime('now', new DateTimeZone('UTC')),
                true,
            );
        } catch (TalkUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::MESSAGE_NOT_SENT, $e);
        }

        return [
            'conversation_token' => $room->getToken(),
            'messageId' => (int)$comment->getId(),
            'attachmentId' => $attachmentId,
        ];
    }

    /**
     * Sends a plain text message, used for the caption that follows an attachment card.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body
     * @return int Id of the created message
     * @throws InvalidArgumentException When the message is blank or too long
     * @throws ConversationAccessException When the message cannot be sent
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function sendText(Conversation $conversation, string $userId, string $message): int {
        return $this->reply($conversation, $userId, $message)['messageId'];
    }

    /**
     * Checks that the id really is a room share of this conversation, and not a link share, a file of another
     * conversation or something the user cannot see. All of them are the same answer, so the module never reveals
     * that an id exists elsewhere.
     *
     * @param string $userId Authenticated user
     * @param string $token Token of the conversation
     * @param int $attachmentId Room share id reported by talk_read_messages
     * @throws ConversationAccessException When the id is not a share of this conversation
     */
    private function assertAttachmentIsInRoom(string $userId, string $token, int $attachmentId): void {
        try {
            $share = $this->shareManager->getShareById((string)$attachmentId, $userId);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::ATTACHMENT_NOT_FOUND, $e);
        }

        if ($share->getShareType() !== IShare::TYPE_ROOM || $share->getSharedWith() !== $token) {
            throw new ConversationAccessException(Messages::ATTACHMENT_NOT_FOUND);
        }
    }

    /**
     * @param string $message Message body as received from the client
     * @return string The trimmed body
     * @throws InvalidArgumentException When the message is blank or longer than self::MAX_MESSAGE_LENGTH
     */
    private function assertMessage(string $message): string {
        $trimmed = trim($message);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Messages::EMPTY_MESSAGE);
        }
        if (mb_strlen($trimmed) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException(Messages::MESSAGE_TOO_LONG);
        }

        return $trimmed;
    }

    /**
     * The actor type Talk expects for an authenticated user, read from the app so it cannot drift.
     *
     * @param string $userId Authenticated user
     * @return string The actor type constant of the Talk attendee model
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function actorType(string $userId): string {
        return $this->talkServices->conversationConstants($userId)['actorUsers'];
    }
}
