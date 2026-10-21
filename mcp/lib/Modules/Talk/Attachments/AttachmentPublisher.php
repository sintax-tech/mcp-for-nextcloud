<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Messaging\MessageMessages;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

class AttachmentPublisher {
    public function __construct(private \Closure $chatManager, private \Closure $constants, private ITimeFactory $timeFactory, private AttachmentMessage $attachmentMessage, private AttachmentAccess $attachmentAccess) {}
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
            $chatManager = ($this->chatManager)($userId);
            $comment = $chatManager->addSystemMessage(
                $room,
                $conversation->participant,
                ($this->constants)($userId)['actorUsers'],
                $userId,
                $envelope,
                $this->timeFactory->getDateTime('now', new DateTimeZone('UTC')),
                true,
            );
        } catch (TalkUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConversationAccessException(MessageMessages::messageNotSent(), $e);
        }

        return [
            'conversation_token' => $conversation->token(),
            'messageId' => (int)$comment->getId(),
            'attachmentId' => $attachmentId,
        ];
    }
}
