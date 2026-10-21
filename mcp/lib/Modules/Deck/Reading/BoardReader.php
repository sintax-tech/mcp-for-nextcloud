<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Service\BoardService;

class BoardReader {
    public function __construct(private DeckCaller $caller) {
    }

    /**
     * Boards shared with the user, archived ones excluded.
     *
     * @param string $userId UID of the authenticated caller.
     * @return list<Board> Boards visible to the caller, unsorted.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function listBoards(string $userId): array {
        /** @var BoardService $boardService */
        $boardService = $this->caller->bind($userId);
        // `since: -1` disables the change-tracking filter; `fullDetails: false` skips the settings and session enrichment.
        return $boardService->findAll(-1, false, false);
    }
}
