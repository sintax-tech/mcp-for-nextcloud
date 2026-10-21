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

class BatchSender
{
    public function __construct(private MessageSender $writer) {}
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
    public function replyMany(Conversation $conversation, string $userId, array $items): array
    {
        $sent = [];
        $failed = [];

        foreach (array_values($items) as $index => $item) {
            $replyTo = $item['replyTo'] ?? null;
            try {
                $result = $this->writer->reply($conversation, $userId, $item['message'], $replyTo);
                $sent[] = [
                    'index' => $index,
                    'messageId' => $result['messageId'],
                ];
            } catch (ConversationAccessException | InvalidArgumentException $e) {
                $failed[] = ['index' => $index, 'error' => $e->getMessage()];
            }
        }

        return [
            'conversation_token' => $conversation->token(),
            'sent' => $sent,
            'failed' => $failed,
        ];
    }
}
