<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Assignments;

use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Deck\Db\Card;

class AssignmentPlan {
    public function __construct(private DeckFormatterFactory $formatters, private AssignmentAccess $assignments) {
    }

    /**
     * Who is responsible for the card now, who joins, who leaves and what is ignored.
     *
     * Accounts to add go through the same board check the write runs, so a refusal comes with the plan
     * and nothing is written to find it out.
     *
     * @param string $userId UID of the authenticated caller.
     * @param \OCA\Deck\Db\Card $card Card as read, with its assignments.
     * @param array{assign: list<string>, unassign: list<string>} $changes Validated lists of the call.
     * @return array{before: list<array{uid: string, displayName: string}>, after: list<array{uid: string, displayName: string}>, added: list<array{uid: string, displayName: string}>, removed: list<array{uid: string, displayName: string}>, notAssigned: list<string>, alreadyAssigned: list<string>}
     */
    public function build(
        string $userId,
        Card $card,
        array $changes,
    ): array {
        $before = $this->formatters->forUser($userId)->card($card)['assignedUsers'];
        $resolved = AssignmentChanges::resolve($changes['assign'], $changes['unassign'], array_column($before, 'uid'));
        $added = $resolved['add'] === [] ? [] : $this->assignments->validateAssignees($userId, (int) $card->getStackId(), $resolved['add']);
        $removed = array_values(array_filter($before, static fn(array $user): bool => in_array($user['uid'], $resolved['remove'], true)));
        $kept = array_values(array_filter($before, static fn(array $user): bool => !in_array($user['uid'], $resolved['remove'], true)));
        return [
            'before' => $before,
            'after' => [...$kept, ...$added],
            'added' => $added,
            'removed' => $removed,
            'notAssigned' => $resolved['notAssigned'],
            'alreadyAssigned' => $resolved['alreadyAssigned'],
        ];
    }
}
