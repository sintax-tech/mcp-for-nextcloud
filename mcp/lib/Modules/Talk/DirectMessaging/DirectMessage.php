<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\DirectMessaging;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Messaging\MessageMessages;
use OCA\Mcp\Modules\Talk\Messaging\MessageSender;

class DirectMessage
{
    public function __construct(
        private OneToOneConversation $userConversations,
        private ContactResolver $contacts,
        private MessageSender $writer,
        private DirectMessagePlan $preview,
    ) {}
    /**
     * A direct message to an account. The plan resolves the target and stops there, so a user who says no is not
     * left with an empty conversation; the room is created by the confirmed call, and only through the same call
     * the Talk UI uses, which keeps the product's own rule about who may talk to whom.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_message_user
     * @throws InvalidArgumentException When the account id or the message is missing
     * @throws ConversationAccessException When the target is out of reach or the conversation cannot be written in
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array
    {
        $targetId = $this->targetUser($arguments);
        $message = $this->message($arguments);

        $conversation = $this->userConversations->conversation($userId, $targetId);
        $result = $this->writer->reply($conversation, $userId, $message, null);

        return $result + [
            'user' => [
                'id' => $targetId,
                'displayName' => $conversation->displayName($userId),
            ],
        ];
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_message_user
     * @throws InvalidArgumentException When the account id or the message is missing
     * @throws ConversationAccessException When the target is out of reach
     */
    public function preview(string $userId, array $arguments): array
    {
        return $this->preview->directMessage(
            $this->contacts->target($userId, $this->targetUser($arguments)),
            $userId,
            $this->message($arguments),
        );
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
     * @return string Account id of the message target
     * @throws InvalidArgumentException When the account is missing
     */
    private function targetUser(array $arguments): string
    {
        return (string) ($arguments['user'] ?? throw new InvalidArgumentException(ContactMessages::invalidUser()));
    }
}
