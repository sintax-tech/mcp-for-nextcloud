<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;

class MoveCardPlan {
    public function __construct(private CardReader $cards, private PlanLocation $locations) {
    }

    /**
     * @param array{cardId: int, stackId: int, order?: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     */
    public function build(array $arguments, string $userId): array {
        $cardId = (int) $arguments['cardId'];
        $card = $this->cards->findCard($userId, $cardId);
        $origin = $this->locations->place($userId, (int) $card->getStackId());
        $destination = $this->locations->place($userId, (int) $arguments['stackId']);
        return [
            'action' => 'deck_move_card',
            'card' => ['id' => $cardId] + CardDate::fields($card),
            'origin' => $origin,
            'destination' => $destination,
            'order' => array_key_exists('order', $arguments) ? (int) $arguments['order'] : null,
            'sameList' => $origin['stackId'] === $destination['stackId'],
            'shared' => array_values(array_filter([$origin, $destination], static fn(array $place): bool => $place['shared'])),
            'recoverable' => true,
            'message' => CardMessages::planMove(),
        ];
    }
}
