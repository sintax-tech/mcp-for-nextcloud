<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Service\StackService;

class StackReader {
    /** @param \Closure(): StackService $stackServiceProvider */
    public function __construct(private DeckCaller $caller, private \Closure $stackServiceProvider) {
    }

    /**
     * Stacks of one board, with their cards embedded by the Deck service.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $boardId Board to list.
     * @return list<Stack> Stacks of the board.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function listStacks(string $userId, int $boardId): array {
        $this->caller->bind($userId);
        /** @var StackService $stackService */
        $stackService = ($this->stackServiceProvider)();
        // `StackService::findAll()` checks PERMISSION_READ on the board before listing its stacks.
        return $stackService->findAll($boardId);
    }
}
