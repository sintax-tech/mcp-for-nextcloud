<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;

class DeleteCardPlan {
    public function __construct(private CardReader $cards, private OwnershipReader $ownership) {
    }

    /**
     * @param array{cardId: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     */
    public function build(array $arguments, string $userId): array {
        $cardId = (int) $arguments['cardId'];
        $card = $this->cards->findCard($userId, $cardId);
        $board = $this->ownership->cardOwnership($userId, $cardId);
        return [
            'action' => 'deck_delete_card',
            'card' => ['id' => $cardId] + CardDate::fields($card),
            'board' => PlanLocation::board($board, $userId),
            'shared' => $board['owner'] === $userId ? [] : [PlanLocation::board($board, $userId)],
            // Deck soft-deletes, but no tool of this app brings a card back, so the plan says so instead of
            // leaving the user to discover it.
            'recoverable' => false,
            'consequence' => CardMessages::planDeleteConsequence(),
            'message' => CardMessages::planDelete(),
        ];
    }
}
