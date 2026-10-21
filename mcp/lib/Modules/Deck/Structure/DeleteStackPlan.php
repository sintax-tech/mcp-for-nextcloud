<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;

class DeleteStackPlan {
    public function __construct(private PlanLocation $locations, private StructureCardCounter $counts) {
    }

    /**
     * A list can only be deleted empty, so the cards are counted for the plan and a list that holds any has no
     * plan at all: the refusal is the answer, as the write would give it.
     *
     * @param array{stackId: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     * @throws DeckRefusalException When the list holds any card.
     */
    public function build(array $arguments, string $userId): array {
        $stackId = (int) $arguments['stackId'];
        $place = $this->locations->place($userId, $stackId);
        $cards = $this->counts->stackCardCount($userId, $stackId);
        if ($cards > 0) {
            throw DeckRefusalException::stackNotEmpty($cards);
        }
        $board = [
            'board' => $place['board'],
            'owner' => $place['owner'],
            'ownerDisplayName' => $place['ownerDisplayName'],
            'shared' => $place['shared'],
        ];
        return [
            'action' => 'deck_delete_stack',
            'stack' => ['id' => $stackId, 'title' => $place['list'], 'cards' => $cards],
            'board' => $board,
            'shared' => $place['shared'] ? [$board] : [],
            'recoverable' => true,
            'consequence' => StructureMessages::planDeleteStackConsequence(),
            'message' => StructureMessages::planDeleteStack(),
        ];
    }
}
