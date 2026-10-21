<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\DirectMessaging;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\Conversation;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;
use OCP\IUserManager;
use Throwable;

class OneToOneConversation
{
    public function __construct(
        private ContactResolver $contacts,
        private IUserManager $userManager,
        private \Closure $roomService,
        private \Closure $participantService,
        private \Closure $constants,
    ) {}
    /**
     * The direct conversation with the target, created when it does not exist yet, with the caller's participant.
     *
     * This is the product's own call, so the room is created exactly as the Talk UI would: a one to one with both
     * accounts as participants and the caller as owner. Resolving the pair again reuses the room; it does not
     * make repeating a message safe, because a failed send may already have published it.
     *
     * @param string $userId Authenticated user, the sender and the owner of a new room
     * @param string $targetId Account the message is addressed to
     * @throws InvalidArgumentException When the account id is malformed
     * @throws ConversationAccessException When the target is out of reach or the room cannot be used for writing
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function conversation(string $userId, string $targetId): Conversation
    {
        // Re-run the access rule instead of trusting the draft: an approval binds the payload, not the reachability
        // that was true minutes ago.
        $contact = $this->contacts->target($userId, $targetId);

        try {
            $actor = $this->userManager->get($userId);
            $target = $this->userManager->get($contact->id);
            $roomService = ($this->roomService)($userId);
            $participantService = ($this->participantService)($userId);
            $room = $roomService->createOneToOneConversation($actor, $target);
            $participant = $participantService->getParticipant($room, $userId, false);
        } catch (TalkUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Includes the product's own InvalidArgumentException for talking to yourself and the RoomNotFoundException
            // it throws for a target the caller cannot enumerate: all of them are the same refusal to the caller.
            throw new ConversationAccessException(ContactMessages::userNotReachable(), $e);
        }

        $conversation = new Conversation($room, $participant);
        $this->assertWritable($userId, $conversation);

        return $conversation;
    }

    /**
     * The room may exist yet still refuse writing, the same way any other conversation does.
     *
     * @throws ConversationAccessException When the caller may not write in the room
     * @throws TalkUnavailableException When spreed is unavailable
     */
    private function assertWritable(string $userId, Conversation $conversation): void
    {
        $constants = ($this->constants)($userId);
        $room = $conversation->room;
        $participant = $conversation->participant;

        if (
            $room->isFederatedConversation() ||
            $room->getReadOnly() === $constants['readOnly'] ||
            $room->getType() === $constants['changelogType']
        ) {
            throw new ConversationAccessException(ConversationMessages::conversationNotWritable());
        }
        if (((int) $participant->getPermissions() & $constants['chatPermission']) === 0) {
            throw new ConversationAccessException(ConversationMessages::conversationNotWritable());
        }
    }
}
