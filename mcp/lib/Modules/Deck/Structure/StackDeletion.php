<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation;

class StackDeletion {
    public function __construct(
        private OwnershipReader $ownership,
        private VerifiedWrite $writes,
        private EmptyStructureDeletion $deletion,
        private StructureStateReader $structureState,
    ) {
    }

    /**
     * The payload is the deleted list.
     *
     * @param array<string, mixed> $arguments Requires `stackId`; accepts `confirm_shared`.
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     */
    public function execute(array $arguments, string $userId): array {
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->stackOwnership($userId, (int) $arguments['stackId']));
        if ($confirmation !== null) {
            return $confirmation;
        }
        $stackId = (int) $arguments['stackId'];
        $warnings = [];
        // The refusals of the capability (cards in the list, a card that arrived during the delete) are decided by
        // it; any other exception is followed by a look in the trash of the board.
        $stack = $this->writes->afterWrite(
            'deck_delete_stack',
            'deck_list_stacks',
            fn() => $this->deletion->deleteEmptyStack($userId, $stackId),
            fn() => $this->structureState->deletedStack($userId, $stackId),
            $warnings,
        );
        return [
            'id' => (int) $stack->getId(),
            'boardId' => (int) $stack->getBoardId(),
            'title' => (string) $stack->getTitle(),
            'deleted' => true,
        ] + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
