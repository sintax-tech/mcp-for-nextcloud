<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Groups;

use OCA\Mcp\Modules\Talk\DirectMessaging\DirectContact;
use OCA\Mcp\Modules\Talk\Presentation\PlanMessages;

class GroupPlan
{
    /**
     * Preview of a new group. It carries the name and the people who would be invited, and no token: there is
     * no conversation yet, and a preview that created one would leave the user with a group they never asked for.
     *
     * @param string $userId Authenticated user, the future owner
     * @param string $name Name of the group, normalized exactly as it will be stored
     * @param list<DirectContact> $participants Accounts that would be invited
     * @return array{requiresConfirmation:true, action:string, draft:array{name:string, participants:list<array{id:string, displayName:string}>}, message:string}
     * @throws InvalidArgumentException When the name is blank or too long
     */
    public function group(string $userId, string $name, array $participants): array
    {
        $title = GroupCreation::normalizeName($name);
        $invited = array_map(static fn(DirectContact $contact): array => $contact->describe(), $participants);

        return [
            'requiresConfirmation' => true,
            'action' => 'talk_create_group',
            'draft' => ['name' => $title, 'participants' => $invited],
            'message' => PlanMessages::confirmationInstructionGroup(
                $title,
                PlanMessages::invitedGuests(array_column($invited, 'displayName')),
            ),
        ];
    }
}
