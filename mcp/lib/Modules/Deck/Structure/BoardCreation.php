<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;

class BoardCreation {
    public function __construct(
        private StructureStateReader $structureState,
        private VerifiedWrite $writes,
        private BoardCreator $boardCreator,
        private BlueprintProvisioner $provisioner,
    ) {
    }

    /**
     * The payload carries `created` (the ids of the board, lists and cards), `failed` (what was not created and
     * why), `warnings`, `complete` and a `summary` that says what exists.
     *
     * @param array<string, mixed> $arguments Requires `title`; accepts `color` and `stacks`.
     * @param string $userId UID of the authenticated caller, who owns the board and every card in it.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     * @throws \InvalidArgumentException When any part of the structure is refused, before anything is written.
     */
    public function execute(array $arguments, string $userId): array {
        $blueprint = BoardBlueprint::parse($arguments, $userId);
        // The one step whose failure is a plain error: until it succeeds nothing exists. Deck inserts the board
        // before its labels and activity, so the boards of the caller before and after tell whether it does.
        $before = array_map(static fn($board): int => (int) $board->getId(), $this->structureState->ownedBoards($userId));
        $warnings = [];
        $probable = false;
        $board = $this->writes->afterApproximateWrite(
            'deck_create_board',
            'deck_list_boards',
            fn() => $this->boardCreator->createBoard($userId, $blueprint['title'], $blueprint['color']),
            fn() => $this->structureState->newBoard($userId, $blueprint['title'], $before),
            $warnings,
            $probable,
        );
        return $this->provisioner->build((int) $board->getId(), (string) $board->getTitle(), $blueprint, $userId, $warnings, $probable);
    }
}
