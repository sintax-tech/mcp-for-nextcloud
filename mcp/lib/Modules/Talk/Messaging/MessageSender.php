<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

class MessageSender
{
    public function __construct(
        private \Closure $participantService,
        private \Closure $chatManager,
        private \Closure $constants,
        private ITimeFactory $timeFactory,
        private ReplyTargetReader $parents,
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
    public function reply(Conversation $conversation, string $userId, string $message, ?int $replyTo = null): array
    {
        $body = MessageText::normalizeMessage($message);
        if ($replyTo !== null && $replyTo < 1) {
            throw new InvalidArgumentException(MessageMessages::invalidIdentifier());
        }

        $participantService = ($this->participantService)($userId);
        $chatManager = ($this->chatManager)($userId);
        $room = $conversation->room;

        $parent = $replyTo === null ? null : $this->parents->quoteTarget($conversation, $userId, $replyTo);

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
            throw new ConversationAccessException(MessageMessages::messageNotSent(), $e);
        }

        return [
            'conversation_token' => $conversation->token(),
            'messageId' => (int) $comment->getId(),
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
    public function sendText(Conversation $conversation, string $userId, string $message): int
    {
        return $this->reply($conversation, $userId, $message)['messageId'];
    }

    /**
     * The actor type Talk expects for an authenticated user, read from the app so it cannot drift.
     *
     * @param string $userId Authenticated user
     * @return string The actor type constant of the Talk attendee model
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function actorType(string $userId): string
    {
        return ($this->constants)($userId)['actorUsers'];
    }
}
