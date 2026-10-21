<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Mcp\Modules\Deck\Assignments\AssignedUsers;
use OCA\Mcp\Modules\Deck\Cards\CardInput;

class CreateCardPlan {
    public function __construct(private PlanLocation $locations, private AssignmentAccess $assignments) {
    }

    /**
     * @param array{stackId: int, title: string, description?: string, duedate?: string|null, assignees?: list<string>} $arguments
     * @param string $userId UID of the authenticated caller, also the owner of the card to be created.
     * @return array<string, mixed>
     */
    public function build(array $arguments, string $userId): array {
        $place = $this->locations->place($userId, (int) $arguments['stackId']);
        $assignees = array_key_exists('assignees', $arguments) ? $this->assignments->validateAssignees($userId, (int) $arguments['stackId'], AssignedUsers::assignees($arguments['assignees'])) : null;
        return [
            'action' => 'deck_create_card',
            'card' => [
                'title' => CardInput::requireTitle((string) $arguments['title']),
                'description' => CardInput::requireDescription((string) ($arguments['description'] ?? '')),
                'dueDate' => CardInput::duedate($arguments['duedate'] ?? null),
                'owner' => $userId,
            ] + ($assignees === null ? [] : ['assignees' => $assignees]),
            'destination' => $place,
            'shared' => $place['shared'] ? [$place] : [],
            'recoverable' => true,
            'message' => CardMessages::planCreate(),
        ];
    }
}
