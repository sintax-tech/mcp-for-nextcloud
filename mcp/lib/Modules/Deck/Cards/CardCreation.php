<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation;
use OCA\Mcp\Modules\Deck\Assignments\AssignedUsers;
use OCA\Mcp\Modules\Deck\Execution\WriteMessages;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Deck\Cards\CardInput;

class CardCreation {
    public function __construct(
        private DeckFormatterFactory $formatters,
        private OwnershipReader $ownership,
        private AssignmentAccess $assignments,
        private VerifiedWrite $writes,
        private CardWriter $writer,
        private CardStateReader $state,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The payload is the created card.
     *
     * @param array<string, mixed> $arguments Requires `stackId` and `title`; accepts `description`, `duedate` and `assignees`.
     * @param string $userId UID of the authenticated caller, also the card owner.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     */
    public function execute(array $arguments, string $userId): array {
        $formatter = $this->formatters->forUser($userId);
        // The stack decides whose board the card lands on; without the user's confirmation the
        // answer is the shared-resource payload and nothing is created.
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->stackOwnership($userId, (int) $arguments['stackId']));
        if ($confirmation !== null) {
            return $confirmation;
        }
        $assignees = array_key_exists('assignees', $arguments) ? $this->assignments->validateAssignees($userId, (int) $arguments['stackId'], AssignedUsers::assignees($arguments['assignees'])) : [];
        $stackId = (int) $arguments['stackId'];
        $title = CardInput::requireTitle((string) $arguments['title']);
        $description = CardInput::requireDescription((string) ($arguments['description'] ?? ''));
        $duedate = CardInput::duedate($arguments['duedate'] ?? null);
        $warnings = [];
        // Deck inserts the card before its activity, events and enrichment: a failure after that point is a card
        // that exists, found again by owner, title and creation time.
        $probable = false;
        $card = $this->writes->afterApproximateWrite(
            'deck_create_card',
            'deck_list_cards',
            fn() => $this->writer->createCard($userId, $stackId, $title, $description, $duedate),
            fn() => $this->state->findCreatedCard($userId, $stackId, $title, []),
            $warnings,
            $probable,
        );
        if ($probable) {
            // Found by likeness, so maybe somebody else's card: nothing is written on it.
            if ($assignees !== []) {
                $warnings[] = WriteMessages::probableCardNotAssigned();
            }
            return ['confirmed' => 'probable'] + $formatter->card($card) + ['warnings' => $warnings];
        }
        $assignmentFailed = false;
        foreach ($assignees as $assignee) {
            try {
                $assignment = $this->writes->afterWrite(
                    'deck_create_card',
                    'deck_list_cards',
                    fn() => $this->assignments->assignCardUser($userId, (int) $card->getId(), $assignee['uid']),
                    fn() => $this->assignments->assignmentOf($userId, (int) $card->getId(), $assignee['uid']),
                    $warnings,
                );
                $card->setAssignedUsers([...$card->getAssignedUsers() ?? [], $assignment]);
            } catch (\Throwable $e) {
                // Creation succeeded: never turn this into an error that encourages a duplicate retry.
                $this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
                $assignmentFailed = true;
            }
        }
        if ($assignmentFailed) {
            $warnings[] = Translator::t('The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.');
        }
        return $formatter->card($card) + ($warnings === [] ? [] : ['warnings' => $warnings]);
    }
}
