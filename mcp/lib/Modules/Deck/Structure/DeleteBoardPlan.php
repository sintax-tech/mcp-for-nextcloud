<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Reading\StackReader;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;

class DeleteBoardPlan {
    public function __construct(
        private OwnershipReader $ownership,
        private StructureCardCounter $counts,
        private StackReader $stacks,
    ) {
    }

    /**
     * A board can only be deleted by its owner and empty, so a board of somebody else or one that holds a card has
     * no plan: the refusal is the answer, as the write would give it.
     *
     * @param array{boardId: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     * @throws DeckRefusalException When the caller is not the owner or any list holds a card.
     */
    public function build(array $arguments, string $userId): array {
        $boardId = (int) $arguments['boardId'];
        $ownership = $this->ownership->boardOwnership($userId, $boardId);
        if ($ownership['owner'] !== $userId) {
            throw DeckRefusalException::boardNotOwned();
        }
        $cards = $this->counts->boardCardCount($userId, $boardId);
        if ($cards > 0) {
            throw DeckRefusalException::boardNotEmpty($cards);
        }
        return [
            'action' => 'deck_delete_board',
            'board' => ['id' => $boardId, 'title' => $ownership['name'], 'cards' => $cards],
            'stacks' => array_map(
                static fn(Stack $stack): string => (string) $stack->getTitle(),
                $this->stacks->listStacks($userId, $boardId),
            ),
            'shared' => [],
            'recoverable' => true,
            'consequence' => StructureMessages::planDeleteBoardConsequence(),
            'message' => StructureMessages::planDeleteBoard(),
        ];
    }
}
