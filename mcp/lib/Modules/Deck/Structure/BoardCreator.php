<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Db\Board;

class BoardCreator {
    public function __construct(private DeckCaller $caller) {
    }

    /**
     * Creates a board owned by the caller, with the default labels Deck gives every new board.
     *
     * @param string $userId UID of the authenticated caller, the owner of the new board.
     * @param string $title Board title, 1 to 100 characters.
     * @param string $color Six hexadecimal digits, without `#`.
     * @return Board Created board.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function createBoard(
        string $userId,
        string $title,
        string $color,
    ): Board {
        $boardService = $this->caller->bind($userId);
        // Deck v1.17.5 `create(string $title, string $userId, string $color)`; it adds the four default labels.
        return $boardService->create($title, $userId, $color);
    }
}
