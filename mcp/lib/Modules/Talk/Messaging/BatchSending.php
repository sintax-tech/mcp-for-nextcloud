<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Messaging;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use Psr\Log\LoggerInterface;

class BatchSending
{
    public function __construct(
        private BatchSender $writer,
        private ConversationResolver $resolver,
        private BatchPlan $preview,
        private LoggerInterface $logger,
    ) {}
    /**
     * A batch of messages in one conversation. The plan carries the whole list, because a batch is approved
     * as a list: a tool that let the agent send that list with one item changed would be sending something
     * else. Every quoted id is resolved while the plan is built, so a wrong citation fails before anything is
     * published rather than halfway through.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_send_batch
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is malformed
     * @throws ConversationAccessException When the conversation cannot be written in or a citation does not exist
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array
    {
        // The shape of the list is a client mistake, so it is checked before any conversation is looked up.
        $items = $this->batchItems($arguments);
        $conversation = $this->resolver->resolveForWriting($userId, $this->token($arguments));

        $result = $this->writer->replyMany($conversation, $userId, $items);

        if ($result['failed'] !== []) {
            // Part of the batch is in the room now, so the result has to tell which: a generic failure would invite
            // a retry that republishes what already went out.
            $this->logger->warning('Talk sent only part of the batch in conversation {token}', [
                'token' => $conversation->token(),
                'sent' => count($result['sent']),
                'failed' => count($result['failed']),
            ]);
        }

        return $result;
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_send_batch
     * @throws InvalidArgumentException When the batch is empty, too long, or an item is malformed
     * @throws ConversationAccessException When the conversation cannot be written in or a citation does not exist
     */
    public function preview(string $userId, array $arguments): array
    {
        return $this->preview->batch(
            $this->resolver->resolveForWriting($userId, $this->token($arguments)),
            $userId,
            $this->batchItems($arguments),
        );
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return list<array{message:string, replyTo?:int|null>} Messages of the batch, in the order received
     * @throws InvalidArgumentException When messages is not a list of items with a message
     */
    private function batchItems(array $arguments): array
    {
        $messages = $arguments['messages'] ?? throw new InvalidArgumentException(MessageMessages::emptyBatch());
        if (!is_array($messages) || !array_is_list($messages) || $messages === []) {
            throw new InvalidArgumentException(MessageMessages::emptyBatch());
        }
        // A list longer than a user can read is a mistake in the call, and it is refused here so that neither
        // the plan nor the conversation behind it is built for something nobody could have approved.
        if (count($messages) > BatchPlan::MAX_BATCH) {
            throw new InvalidArgumentException(MessageMessages::tooManyMessages(BatchPlan::MAX_BATCH));
        }

        $items = [];
        foreach ($messages as $item) {
            if (!is_array($item) || !isset($item['message']) || !is_string($item['message'])) {
                throw new InvalidArgumentException(MessageMessages::emptyMessage());
            }
            $replyTo = $item['reply_to'] ?? null;
            $items[] = [
                'message' => $item['message'],
                'replyTo' => $replyTo === null ? null : (int) $replyTo,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The conversation token
     * @throws InvalidArgumentException When the token is missing
     */
    private function token(array $arguments): string
    {
        return (string) ($arguments['conversation_token'] ??
            throw new InvalidArgumentException(ConversationMessages::invalidToken()));
    }
}
