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

class CardDeletion {
    public function __construct(
        private DeckFormatterFactory $formatters,
        private OwnershipReader $ownership,
        private VerifiedWrite $writes,
        private CardWriter $writer,
        private CardStateReader $state,
    ) {
    }

    /**
     * The payload is the deleted card.
     *
     * @param array<string, mixed> $arguments Requires `cardId`; accepts `confirm` and `confirm_shared`.
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     */
    public function execute(array $arguments, string $userId): array {
        $formatter = $this->formatters->forUser($userId);
        // The user already confirmed the plan; `confirm_shared` is what the board of somebody
        // else still needs before the card is deleted.
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->cardOwnership($userId, (int) $arguments['cardId']));
        if ($confirmation !== null) {
            return $confirmation;
        }
        $cardId = (int) $arguments['cardId'];
        $warnings = [];
        // Deck stamps `deleted_at` before the activity and the notifications: the card read back says whether it went.
        $card = $this->writes->afterWrite(
            'deck_delete_card',
            'deck_read_card',
            fn() => $this->writer->deleteCard($userId, $cardId),
            function () use ($userId, $cardId) {
                $card = $this->state->cardState($userId, $cardId);
                return (int) $card->getDeletedAt() > 0 ? $card : null;
            },
            $warnings,
        );
        return $formatter->card($card) + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
