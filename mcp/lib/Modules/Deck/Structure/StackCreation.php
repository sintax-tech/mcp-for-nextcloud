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

class StackCreation {
    public function __construct(
        private OwnershipReader $ownership,
        private StructureStateReader $structureState,
        private VerifiedWrite $writes,
        private StackCreator $stackCreator,
    ) {
    }

    /**
     * The payload is the created list.
     *
     * @param array<string, mixed> $arguments Requires `boardId` and `title`; accepts `order` and `confirm_shared`.
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     * @throws \InvalidArgumentException When the title is blank once trimmed, before any Deck lookup.
     */
    public function execute(array $arguments, string $userId): array {
        $title = BoardBlueprint::title((string) $arguments['title'], 'title');
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->boardOwnership($userId, (int) $arguments['boardId']));
        if ($confirmation !== null) {
            return $confirmation;
        }
        $boardId = (int) $arguments['boardId'];
        $order = array_key_exists('order', $arguments) ? (int) $arguments['order'] : null;
        // The lists before the write, so a creation that threw is told apart from a list that already had the title:
        // Deck inserts the list before its activity and the board event.
        $before = array_map(static fn($stack): int => (int) $stack->getId(), $this->structureState->stacksOf($userId, $boardId));
        $warnings = [];
        $probable = false;
        $stack = $this->writes->afterApproximateWrite(
            'deck_create_stack',
            'deck_list_stacks',
            fn() => $this->stackCreator->createStack($userId, $boardId, $title, $order),
            fn() => StructureStateReader::newStack($this->structureState->stacksOf($userId, $boardId), $title, $before),
            $warnings,
            $probable,
        );
        return ($probable ? ['confirmed' => 'probable'] : []) + [
            'id' => (int) $stack->getId(),
            'boardId' => (int) $stack->getBoardId(),
            'title' => (string) $stack->getTitle(),
            'order' => (int) $stack->getOrder(),
        ] + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
