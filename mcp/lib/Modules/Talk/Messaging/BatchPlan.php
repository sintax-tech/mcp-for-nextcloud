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
use OCA\Mcp\Modules\Talk\Presentation\ConversationPlan;

class BatchPlan
{
    public const MAX_BATCH = 50;
    public function __construct(private QuotedMessage $quoted) {}
    /**
     * Preview of a whole batch. Every item is shown with the very text that will be stored and, when the item
     * quotes something, with who is being answered: a user approving a list has to see the list, not a promise
     * of one.
     *
     * @param Conversation $conversation Conversation already validated for writing
     * @param string $userId Authenticated user, the author of the messages
     * @param list<array{message:string, replyTo?:int|null}> $items Messages as received from the client
     * @return array{requiresConfirmation:true, action:string, conversation:array{token:string, displayName:string}, draft:array{messages:list<array{message:string, replyTo?:array{id:int, author:string, excerpt:string}}>}, message:string}
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is not usable
     * @throws ConversationAccessException When a quoted message does not exist in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function batch(Conversation $conversation, string $userId, array $items): array
    {
        $normalized = self::normalizedBatch($items);

        $draft = [];
        foreach ($normalized as $item) {
            $shown = ['message' => $item['message']];
            // A quoted id that does not exist is a mistake in the batch, not one failed item: sending the rest
            // would show the user something different from what they approved.
            if ($item['replyTo'] !== null) {
                $shown['replyTo'] = $this->quoted->describe($conversation, $userId, $item['replyTo']);
            }
            $draft[] = $shown;
        }

        return ConversationPlan::payload('talk_send_batch', $conversation, $userId, ['messages' => $draft]);
    }

    /**
     * The shape of a batch has to be checked before any conversation lookup is started, because a list with a
     * blank item is a mistake in the call and not one failed item of a list.
     *
     * @param list<array{message:string, replyTo?:int|null}> $items Messages of a call
     * @return list<array{message:string, replyTo:int|null}>
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is not usable
     */
    private static function normalizedBatch(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException(MessageMessages::emptyBatch());
        }
        if (count($items) > self::MAX_BATCH) {
            throw new InvalidArgumentException(MessageMessages::tooManyMessages(self::MAX_BATCH));
        }

        $normalized = [];
        foreach (array_values($items) as $item) {
            $normalized[] = [
                'message' => MessageText::normalizeMessage($item['message'] ?? ''),
                'replyTo' => $item['replyTo'] ?? null,
            ];
        }

        return $normalized;
    }
}
