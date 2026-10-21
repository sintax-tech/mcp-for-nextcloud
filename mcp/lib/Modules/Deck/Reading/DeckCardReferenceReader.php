<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader;

class DeckCardReferenceReader implements CardReferenceReader {
    public function __construct(private CardReader $cards) {
    }

    public function read(string $userId, int $cardId): array {
        $card = $this->cards->findCard($userId, $cardId);
        return [
            'cardId' => $cardId,
            'boardId' => $this->cards->cardBoardId($userId, $cardId),
            'title' => (string) $card->getTitle(),
        ];
    }
}
