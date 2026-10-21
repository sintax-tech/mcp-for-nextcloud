<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException as CommentNotFoundException;
use Throwable;

class ReplyTargetReader
{
    public function __construct(private \Closure $chatManager) {}
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
    public function quoteTarget(Conversation $conversation, string $userId, int $replyTo): IComment
    {
        if ($replyTo < 1) {
            throw new InvalidArgumentException(MessageMessages::invalidIdentifier());
        }

        try {
            return ($this->chatManager)($userId)->getParentComment($conversation->room, (string) $replyTo);
        } catch (CommentNotFoundException $e) {
            // A missing id and an id from another conversation are the same answer.
            throw new ConversationAccessException(MessageMessages::replyTargetNotFound(), $e);
        } catch (Throwable $e) {
            throw new ConversationAccessException(MessageMessages::replyTargetNotFound(), $e);
        }
    }
}
