<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use DateTimeZone;
use InvalidArgumentException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException as CommentNotFoundException;
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
        private AttachmentAccess $attachmentAccess,
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
        $body = self::normalizeMessage($message);
        if ($replyTo !== null && $replyTo < 1) {
            throw new InvalidArgumentException(Messages::invalidIdentifier());
        }

        $participantService = $this->talkServices->participantService($userId);
        $chatManager = $this->talkServices->chatManager($userId);
        $room = $conversation->room;

        $parent = $replyTo === null ? null : $this->quoteTarget($conversation, $userId, $replyTo);

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
            throw new ConversationAccessException(Messages::messageNotSent(), $e);
        }

        return [
            'conversation_token' => $conversation->token(),
            'messageId' => (int)$comment->getId(),
        ];
    }

    /**
     * Sends several messages in the same conversation, reporting each one separately.
     *
     * A batch is sent in order and stops nothing: an item Talk refuses is reported as failed and the next one is
     * still attempted, because a user asking for three messages wants the two that work and to know about the third.
     * Nothing is retried here. A failure that follows a successful item must not make the caller repeat the whole
     * batch, and the sent list is what tells them what already exists.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the messages
     * @param list<array{message:string, replyTo?:int|null}> $items Messages to send, in order
     * @return array{conversation_token:string, sent:list<array{index:int, messageId:int}>, failed:list<array{index:int, error:string}>}
     * @throws InvalidArgumentException When an item is blank or too long, or a quoted id is not a positive integer
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function replyMany(Conversation $conversation, string $userId, array $items): array {
        $sent = [];
        $failed = [];

        foreach (array_values($items) as $index => $item) {
            $replyTo = $item['replyTo'] ?? null;
            try {
                $result = $this->reply($conversation, $userId, $item['message'], $replyTo);
                $sent[] = ['index' => $index, 'messageId' => $result['messageId']];
            } catch (ConversationAccessException|InvalidArgumentException $e) {
                $failed[] = ['index' => $index, 'error' => $e->getMessage()];
            }
        }

        return [
            'conversation_token' => $conversation->token(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * Resolves the message a reply quotes, so the draft can show what the user is answering before it exists.
     *
     * @param Conversation $conversation Conversation the reply would land in
     * @param string $userId Authenticated user
     * @param int $replyTo Id of the quoted message, as talk_read_messages reports it
     * @return IComment The parent comment Talk will attach the reply to
     * @throws InvalidArgumentException When the id is not a positive integer
     * @throws ConversationAccessException When the message is not in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function quoteTarget(Conversation $conversation, string $userId, int $replyTo): IComment {
        if ($replyTo < 1) {
            throw new InvalidArgumentException(Messages::invalidIdentifier());
        }

        try {
            return $this->talkServices->chatManager($userId)
                ->getParentComment($conversation->room, (string)$replyTo);
        } catch (CommentNotFoundException $e) {
            // A missing id and an id from another conversation are the same answer.
            throw new ConversationAccessException(Messages::replyTargetNotFound(), $e);
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::replyTargetNotFound(), $e);
        }
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
        $this->attachmentAccess->requireRoomShareOf($conversation, $userId, $attachmentId);

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
            throw new ConversationAccessException(Messages::messageNotSent(), $e);
        }

        return [
            'conversation_token' => $conversation->token(),
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
     * The message body as Talk will store it. A draft has to show the very text that will be sent, so the
     * preview normalizes with the same rule instead of trimming a second time on its own.
     *
     * @param string $message Message body as received from the client
     * @return string The trimmed body
     * @throws InvalidArgumentException When the message is blank or longer than self::MAX_MESSAGE_LENGTH
     */
    public static function normalizeMessage(string $message): string {
        $trimmed = trim($message);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Messages::emptyMessage());
        }
        if (mb_strlen($trimmed) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidArgumentException(Messages::messageTooLong());
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
