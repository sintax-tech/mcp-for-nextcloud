<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Groups;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Access\ConversationAccessException;
use OCA\Mcp\Modules\Talk\DirectMessaging\ContactMessages;
use OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver;
use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;

class GroupWorkflow
{
    public function __construct(
        private GroupCreation $groups,
        private ContactResolver $userConversations,
        private GroupPlan $preview,
    ) {}
    /**
     * A new group. Both steps of the product's API happen only here, in the confirmed call: the plan resolves the
     * people who would be invited and stops, so an abandoned preview leaves no room behind.
     *
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> Result data of talk_create_group
     * @throws InvalidArgumentException When the name or the participant list is not usable
     * @throws ConversationAccessException When an account is out of reach or the product refuses the creation
     * @throws TalkUnavailableException When spreed is unavailable
     */
    public function execute(string $userId, array $arguments): array
    {
        $name = GroupCreation::normalizeName((string) ($arguments['name'] ?? ''));
        $participants = $this->participantIds($arguments);

        return $this->groups->create($userId, $name, $participants);
    }

    /**
     * @param string $userId Authenticated user
     * @param array<string, mixed> $arguments Validated arguments
     * @return array<string, mixed> The plan of talk_create_group
     * @throws InvalidArgumentException When the name or the participant list is not usable
     * @throws ConversationAccessException When an account is out of reach
     */
    public function preview(string $userId, array $arguments): array
    {
        return $this->preview->group(
            $userId,
            GroupCreation::normalizeName((string) ($arguments['name'] ?? '')),
            $this->userConversations->contacts($userId, $this->participantIds($arguments), true),
        );
    }

    /**
     * @param array<string, mixed> $arguments Validated arguments
     * @return list<string> Accounts the call would invite, in the order received
     * @throws InvalidArgumentException When participants is not a list of account ids
     */
    private function participantIds(array $arguments): array
    {
        $participants = $arguments['participants'] ?? [];
        if (!is_array($participants) || !array_is_list($participants)) {
            throw new InvalidArgumentException(ContactMessages::invalidUser());
        }

        $ids = [];
        foreach ($participants as $participantId) {
            if (!is_string($participantId)) {
                throw new InvalidArgumentException(ContactMessages::invalidUser());
            }
            $ids[] = $participantId;
        }

        return $ids;
    }
}
