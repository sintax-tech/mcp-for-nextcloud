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
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use Throwable;

class ContactResolver
{
    public const MAX_TARGET_LENGTH = 64;
    public function __construct(private IUserManager $userManager, private IShareManager $shareManager) {}
    /**
     * The target account, read only. Nothing is created and nothing is written.
     *
     * @param string $userId Authenticated user
     * @param string $targetId Account the caller wants to talk to
     * @throws InvalidArgumentException When the account id is malformed
     * @throws ConversationAccessException When the target does not exist, is the caller itself, or is not reachable
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function target(string $userId, string $targetId): DirectContact
    {
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
    public function contacts(string $userId, array $targetIds, bool $nameTheAccount = false): array
    {
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
                throw new ConversationAccessException(ContactMessages::userNotReachable());
            }

            foreach ($wanted as $targetId) {
                $target = $this->userManager->get($targetId);
                // Same answer for missing and out of reach: the tool must not map out who exists on the server.
                if ($target === null || !$this->shareManager->currentUserCanEnumerateTargetUser($actor, $target)) {
                    throw new ConversationAccessException(self::unreachable($targetId, $nameTheAccount));
                }
                $contacts[$targetId] = new DirectContact(
                    $targetId,
                    (string) ($this->userManager->getDisplayName($targetId) ?? $targetId),
                );
            }
        } catch (ConversationAccessException | InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConversationAccessException(ContactMessages::userNotReachable(), $e);
        }

        return array_values($contacts);
    }

    /**
     * @throws InvalidArgumentException When the account id is malformed
     */
    private function assertValidTarget(string $targetId): void
    {
        if (
            $targetId === '' ||
            mb_strlen($targetId) > self::MAX_TARGET_LENGTH ||
            preg_match('/[\/\\\\\x00-\x1F]/', $targetId) === 1
        ) {
            throw new InvalidArgumentException(ContactMessages::invalidUser());
        }
    }

    /** The same refusal for a missing and an unreachable account, optionally naming the id the caller sent. */
    private static function unreachable(string $targetId, bool $nameTheAccount): string
    {
        return $nameTheAccount
            ? ContactMessages::participantNotReachable($targetId)
            : ContactMessages::userNotReachable();
    }
}
