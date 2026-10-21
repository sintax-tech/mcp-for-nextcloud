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
use OCA\Mcp\Modules\Talk\Presentation\ConversationPlan;

class ReplyPlan
{
    public function __construct(private QuotedMessage $quoted) {}
    /**
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the message
     * @param string $message Message body, normalized exactly as it will be sent
     * @param int|null $replyTo Id of the quoted message, when the reply quotes one
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{message:string, replyTo?:array{id:int, author:string, excerpt:string}}, message:string}
     * @throws InvalidArgumentException When the message is blank or too long, or the quoted id is not a positive integer
     * @throws ConversationAccessException When the quoted message does not exist in this conversation
     */
    public function reply(Conversation $conversation, string $userId, string $message, ?int $replyTo): array
    {
        $text = MessageText::normalizeMessage($message);

        $draft = ['message' => $text];

        if ($replyTo !== null) {
            $draft['replyTo'] = $this->quoted->describe($conversation, $userId, $replyTo);
        }

        return ConversationPlan::payload('talk_reply', $conversation, $userId, $draft);
    }
}
