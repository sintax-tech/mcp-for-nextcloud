<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation;

class CardMovement {
    public function __construct(
        private DeckFormatterFactory $formatters,
        private OwnershipReader $ownership,
        private CardStateReader $state,
        private VerifiedWrite $writes,
        private CardWriter $writer,
    ) {
    }

    /**
     * The payload is the moved card.
     *
     * @param array<string, mixed> $arguments Requires `cardId` and `stackId`; accepts `order`.
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     */
    public function execute(array $arguments, string $userId): array {
        $formatter = $this->formatters->forUser($userId);
        // Origin and destination are two boards and either can belong to somebody else; both
        // are gated before the card moves anywhere, and the origin is checked first.
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->cardOwnership($userId, (int) $arguments['cardId']));
        if ($confirmation === null) {
            $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->stackOwnership($userId, (int) $arguments['stackId']));
        }
        if ($confirmation !== null) {
            return $confirmation;
        }
        $cardId = (int) $arguments['cardId'];
        $stackId = (int) $arguments['stackId'];
        $order = array_key_exists('order', $arguments) ? (int) $arguments['order'] : null;
        // Read before the move, so a move that threw can be told from one that never happened: Deck saves the
        // new list before the activity and the reorder of the destination.
        $before = $this->state->cardState($userId, $cardId);
        $warnings = [];
        $card = $this->writes->afterWrite(
            'deck_move_card',
            'deck_read_card',
            fn() => $this->writer->moveCard($userId, $cardId, $stackId, $order),
            function () use ($userId, $cardId, $stackId, $before) {
                $card = $this->state->cardState($userId, $cardId);
                $moved = (int) $card->getStackId() === $stackId && (int) $card->getDeletedAt() === 0 && ((int) $before->getStackId() !== $stackId || (int) $card->getLastModified() !== (int) $before->getLastModified());
                return $moved ? $card : null;
            },
            $warnings,
        );
        return $formatter->card($card) + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
