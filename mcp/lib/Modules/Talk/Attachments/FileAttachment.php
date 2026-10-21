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
use Psr\Log\LoggerInterface;

class FileAttachment {
    public function __construct(private FileSharer $sharer, private ConversationResolver $resolver, private AttachPlan $preview, private LoggerInterface $logger) {}
    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_attach_file
     * @throws InvalidArgumentException When the path is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array {
        $path = $this->path($arguments);
        $caption = $this->optionalMessage($arguments);
        $conversation = $this->writable($userId, $arguments);

        $attached = $this->sharer->attach($conversation, $userId, $path, $caption);
        if (($attached['captionSent'] ?? null) === false) {
            // The card is already in the room, so this is a real failure of the call even though it is not an
            // error result: an operator has to be able to see that the caption was lost.
            $this->logger->error('Talk caption was not sent after the attachment was published', [
                'app' => 'mcp',
                'tool' => 'talk_attach_file',
                'attachmentId' => $attached['attachmentId'],
                'exception' => 'CaptionFailed',
            ]);
        }

        return $attached;
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_attach_file
     * @throws InvalidArgumentException When the path is missing
     * @throws ConversationAccessException When the conversation cannot be reached for writing
     * @throws FileAccessException When the file is missing, not shareable or already shared in this conversation
     */
    public function preview(string $userId, array $arguments): array {
        return $this->preview->attach(
            $this->writable($userId, $arguments),
            $userId,
            $this->path($arguments),
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
     * @return string The file path
     * @throws InvalidArgumentException When the path is missing
     */
    private function path(array $arguments): string {
        return (string)($arguments['path'] ?? throw new InvalidArgumentException(AttachmentMessages::invalidPath()));
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return string|null The optional caption, or null when the tool was called without one
     */
    private function optionalMessage(array $arguments): ?string {
        return isset($arguments['message']) ? (string)$arguments['message'] : null;
    }
}
