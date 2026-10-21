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
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\References\ReferenceLinker;
use OCA\Mcp\Modules\Talk\References\ReferenceMessages;

class Reply
{
    public function __construct(
        private MessageSender $writer,
        private ConversationResolver $resolver,
        private ReplyPlan $preview,
        private ReferenceLinker $references,
    ) {}
    /**
     * Every writing tool reads and checks its arguments before anything is resolved, so a malformed call is
     * reported as the client mistake it is instead of reaching a lookup it should never have started.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_reply
     * @throws InvalidArgumentException When an argument is missing
     * @throws ConversationAccessException When the conversation is missing, not writable, or the quoted message is not there
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array
    {
        $resolved = $this->resolveReply($userId, $arguments);

        return $this->writer->reply($resolved['conversation'], $userId, $resolved['message'], $resolved['replyTo']);
    }

    /**
     * The plan of a reply, including the final text: the reference is resolved here too, so what the user reads
     * is the text with the title and the link that will actually be published.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan
     */
    public function preview(string $userId, array $arguments): array
    {
        $resolved = $this->resolveReply($userId, $arguments);
        $plan = $this->preview->reply($resolved['conversation'], $userId, $resolved['message'], $resolved['replyTo']);

        return $resolved['item'] === null ? $plan : $plan + ['reference' => $resolved['item']];
    }

    /**
     * Everything a reply needs, resolved the same way on the plan and on the confirmed call.
     *
     * The reference is resolved again on the confirmed call instead of trusted from the plan: access is checked
     * at the moment of sending, and a title that changed since the plan changes the text, so the plan the user
     * saw is no longer the text that would go out.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array{conversation:Conversation, message:string, replyTo:int|null, item:array<string, mixed>|null}
     */
    private function resolveReply(string $userId, array $arguments): array
    {
        $message = $this->message($arguments);
        $reference = $this->reference($arguments);
        $conversation = $this->writable($userId, $arguments);

        $item = $reference === null ? null : $this->references->resolve($userId, $reference);
        if ($item !== null) {
            $message = ReferenceLinker::append($message, $item);
        }

        return [
            'conversation' => $conversation,
            'message' => $message,
            'replyTo' => $this->replyTo($arguments),
            'item' => $item,
        ];
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The message body
     * @throws InvalidArgumentException When the message is missing
     */
    private function message(array $arguments): string
    {
        return (string) ($arguments['message'] ?? throw new InvalidArgumentException(MessageMessages::emptyMessage()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed>|null Reference object of talk_reply, or null when absent
     * @throws InvalidArgumentException When reference is not an object
     */
    private function reference(array $arguments): ?array
    {
        $reference = $arguments['reference'] ?? null;
        if ($reference !== null && !is_array($reference)) {
            throw new InvalidArgumentException(ReferenceMessages::invalidReference());
        }

        return $reference;
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return Conversation The conversation, already checked for writing
     * @throws InvalidArgumentException When the token is malformed
     * @throws ConversationAccessException When the conversation is missing or not writable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function writable(string $userId, array $arguments): Conversation
    {
        return $this->resolver->resolveForWriting($userId, $this->token($arguments));
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

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return int|null The quoted message id, when the reply quotes one
     */
    private function replyTo(array $arguments): ?int
    {
        return isset($arguments['reply_to']) ? (int) $arguments['reply_to'] : null;
    }
}
