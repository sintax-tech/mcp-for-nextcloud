<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Access\ConversationResolver;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Messaging\MessageMessages;

class AttachmentQuote {
    public function __construct(private AttachmentPublisher $writer, private ConversationResolver $resolver, private QuotePlan $preview) {}
    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_quote_file
     * @throws InvalidArgumentException When the attachment id is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing, or the attachment is not a share of it
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array {
        $attachmentId = $this->attachmentId($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        return $this->writer->quoteAttachment($conversation, $userId, $attachmentId, $caption);
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_quote_file
     * @throws InvalidArgumentException When the attachment id is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing, or the attachment is not a share of it
     */
    public function preview(string $userId, array $arguments): array {
        return $this->preview->quote(
            $this->writable($userId, $arguments),
            $userId,
            $this->attachmentId($arguments),
            $this->optionalMessage($arguments),
        );
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return Conversation The conversation, already checked for writing
     * @throws InvalidArgumentException When the token is malformed
     * @throws ConversationAccessException When the conversation is missing or not writable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function writable(string $userId, array $arguments): Conversation {
        return $this->resolver->resolveForWriting($userId, $this->token($arguments));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string The conversation token
     * @throws InvalidArgumentException When the token is missing
     */
    private function token(array $arguments): string {
        return (string)($arguments['conversation_token'] ?? throw new InvalidArgumentException(ConversationMessages::invalidToken()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return int The room share id of the attachment
     * @throws InvalidArgumentException When the id is missing
     */
    private function attachmentId(array $arguments): int {
        if (!isset($arguments['attachment_id'])) {
            throw new InvalidArgumentException(MessageMessages::invalidIdentifier());
        }

        return (int)$arguments['attachment_id'];
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string|null The optional caption, or null when the tool was called without one
     */
    private function optionalMessage(array $arguments): ?string {
        return isset($arguments['message']) ? (string)$arguments['message'] : null;
    }
}
