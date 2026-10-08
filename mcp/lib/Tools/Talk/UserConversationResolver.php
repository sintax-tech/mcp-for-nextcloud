<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use Throwable;

/**
 * Resolves the direct conversation between two users, which is what talk_message_user needs instead of a token.
 *
 * Talking to a user is not the same as talking to any room: there is a room per pair, it is created the first
 * time, and the product itself decides whether the caller may even open one with that person. So this asks the
 * same question RoomService::createOneToOneConversation() asks — can this account enumerate that account, which is
 * the rule the Talk UI follows when you start a chat — and answers every refusal the same way, because "that user
 * exists but you may not write to them" is not something this tool may confirm.
 *
 * The plan never creates the room. Showing a plan must not leave an empty conversation behind in the user's
 * Talk list, so the room is opened by the confirmed call, which names the target account and not a token that
 * does not exist yet.
 */
class UserConversationResolver {
    /** Longest account id the tool accepts; real ids are far shorter, the cap only bounds the payload. */
    public const MAX_TARGET_LENGTH = 64;

    public function __construct(
        private TalkServices $talkServices,
        private IUserManager $userManager,
        private IShareManager $shareManager,
    ) {}

    /**
     * The target account, read only. Nothing is created and nothing is written.
     *
     * @param string $userId Authenticated user
     * @param string $targetId Account the caller wants to talk to
     * @throws InvalidArgumentException When the account id is malformed
     * @throws ConversationAccessException When the target does not exist, is the caller itself, or is not reachable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function target(string $userId, string $targetId): DirectContact {
        return $this->contacts($userId, [$targetId])[0];
    }

    /**
     * Accounts the caller may talk to, each one named the way the user knows it. The same rule serves a direct
     * message and the invitations of a new group, so a refusal cannot mean one thing for one tool and another for
     * the next.
     *
     * @param string $userId Authenticated user
     * @param list<string> $targetIds Accounts to resolve, without the caller themself
     * @param bool $nameTheAccount Whether a refusal names the account id, for lists where the caller must know which
     *                             one failed; the id is the caller's own input, so naming it maps nothing new
     * @return list<DirectContact> One contact per id, in the order received and without repetition
     * @throws InvalidArgumentException When an account id is malformed
     * @throws ConversationAccessException When an account does not exist, is the caller, or is not reachable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function contacts(string $userId, array $targetIds, bool $nameTheAccount = false): array {
        // What the client sent is checked first: a malformed id is a mistake in the call whatever the server
        // knows, and it must not turn into a lookup of an account nobody asked for.
        $wanted = [];
        foreach ($targetIds as $targetId) {
            $this->assertValidTarget($targetId);
            // The caller is already in every conversation of theirs, and asking to invite them is a mistake.
            if ($targetId === $userId) {
                throw new ConversationAccessException(self::unreachable($targetId, $nameTheAccount));
            }
            $wanted[$targetId] = $targetId;
        }

        $contacts = [];

        try {
            $actor = $this->userManager->get($userId);
            if ($actor === null) {
                throw new ConversationAccessException(Messages::userNotReachable());
            }

            foreach ($wanted as $targetId) {
                $target = $this->userManager->get($targetId);
                // Same answer for missing and out of reach: the tool must not map out who exists on the server.
                if ($target === null || !$this->shareManager->currentUserCanEnumerateTargetUser($actor, $target)) {
                    throw new ConversationAccessException(self::unreachable($targetId, $nameTheAccount));
                }
                $contacts[$targetId] = new DirectContact(
                    $targetId,
                    (string)($this->userManager->getDisplayName($targetId) ?? $targetId),
                );
            }
        } catch (ConversationAccessException|InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConversationAccessException(Messages::userNotReachable(), $e);
        }

        return array_values($contacts);
    }

    /**
     * The direct conversation with the target, created when it does not exist yet, with the caller's participant.
     *
     * This is the product's own call, so the room is created exactly as the Talk UI would: a one to one with both
     * accounts as participants and the caller as owner. Running it again returns the same room instead of a second
     * one, which is what makes a retry after a failed send safe.
     *
     * @param string $userId Authenticated user, the sender and the owner of a new room
     * @param string $targetId Account the message is addressed to
     * @throws InvalidArgumentException When the account id is malformed
     * @throws ConversationAccessException When the target is out of reach or the room cannot be used for writing
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function conversation(string $userId, string $targetId): Conversation {
        // Re-run the access rule instead of trusting the draft: an approval binds the payload, not the reachability
        // that was true minutes ago.
        $contact = $this->target($userId, $targetId);

        try {
            $actor = $this->userManager->get($userId);
            $target = $this->userManager->get($contact->id);
            $roomService = $this->talkServices->roomService($userId);
            $participantService = $this->talkServices->participantService($userId);
            $room = $roomService->createOneToOneConversation($actor, $target);
            $participant = $participantService->getParticipant($room, $userId, false);
        } catch (TalkUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Includes the product's own InvalidArgumentException for talking to yourself and the RoomNotFoundException
            // it throws for a target the caller cannot enumerate: all of them are the same refusal to the caller.
            throw new ConversationAccessException(Messages::userNotReachable(), $e);
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
    private function assertWritable(string $userId, Conversation $conversation): void {
        $constants = $this->talkServices->conversationConstants($userId);
        $room = $conversation->room;
        $participant = $conversation->participant;

        if ($room->isFederatedConversation()
            || $room->getReadOnly() === $constants['readOnly']
            || $room->getType() === $constants['changelogType']) {
            throw new ConversationAccessException(Messages::conversationNotWritable());
        }
        if (((int)$participant->getPermissions() & $constants['chatPermission']) === 0) {
            throw new ConversationAccessException(Messages::conversationNotWritable());
        }
    }

    /**
     * @throws InvalidArgumentException When the account id is malformed
     */
    private function assertValidTarget(string $targetId): void {
        if ($targetId === '' || mb_strlen($targetId) > self::MAX_TARGET_LENGTH || preg_match('/[\/\\\\\x00-\x1F]/', $targetId) === 1) {
            throw new InvalidArgumentException(Messages::invalidUser());
        }
    }

    /** The same refusal for a missing and an unreachable account, optionally naming the id the caller sent. */
    private static function unreachable(string $targetId, bool $nameTheAccount): string {
        return $nameTheAccount ? Messages::participantNotReachable($targetId) : Messages::userNotReachable();
    }
}
