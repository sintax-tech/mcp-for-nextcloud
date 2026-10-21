<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;

final class GroupPlanRenderer
{
    /**
     * A new group: its name, and everyone the call would invite.
     *
     * @param array<string, mixed> $plan Plan of talk_create_group
     * @return string|null The body, or null when the name of the group is missing
     */
    public static function group(array $plan): ?string
    {
        $name = PlanFields::stringAt($plan, 'draft', 'name');
        $participants = PlanFields::valueAt($plan, 'draft', 'participants');
        if ($name === null || !is_array($participants) || !is_array($plan['draft'] ?? null)) {
            return null;
        }

        $body = [PlanMessages::planCreatingGroup($name)];

        if ($participants === []) {
            $body[] = '';
            $body[] = PlanMessages::planNoGuests();

            return implode("\n", $body);
        }

        $body[] = '';
        $body[] = PlanMessages::planInviting();
        foreach ($participants as $participant) {
            $displayName = is_array($participant) ? PlanFields::stringAt($participant, 'displayName') : null;
            if ($displayName === null) {
                return null;
            }
            $body[] = '- ' . PlanText::strong($displayName);
        }

        return implode("\n", $body);
    }
}
