<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;

class BoardDeletion {
    public function __construct(
        private VerifiedWrite $writes,
        private EmptyStructureDeletion $deletion,
        private StructureStateReader $structureState,
    ) {
    }

    /**
     * The payload is the deleted board.
     *
     * @param array<string, mixed> $arguments Requires `boardId`.
     * @param string $userId UID of the authenticated caller, who must own the board.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     */
    public function execute(array $arguments, string $userId): array {
        $boardId = (int) $arguments['boardId'];
        $warnings = [];
        $board = $this->writes->afterWrite(
            'deck_delete_board',
            'deck_list_boards',
            fn() => $this->deletion->deleteEmptyBoard($userId, $boardId),
            function () use ($userId, $boardId) {
                // Only the owner deletes a board, so the board is among the caller's own, deleted or not.
                foreach ($this->structureState->ownedBoards($userId) as $board) {
                    if ((int) $board->getId() === $boardId) {
                        return (int) $board->getDeletedAt() > 0 ? $board : null;
                    }
                }
                return null;
            },
            $warnings,
        );
        return ['id' => (int) $board->getId(), 'title' => (string) $board->getTitle(), 'deleted' => true] + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
