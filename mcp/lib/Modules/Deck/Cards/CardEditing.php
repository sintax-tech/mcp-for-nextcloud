<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Mcp\Modules\Deck\Execution\VerifiedWrite;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentEditor;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentChanges;
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation;
use OCA\Mcp\Modules\Deck\Assignments\AssignedUsers;
use InvalidArgumentException;
use OCA\Deck\Db\Card;
use OCA\Mcp\Modules\Deck\Cards\CardInput;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;

class CardEditing {
    public function __construct(
        private DeckFormatterFactory $formatters,
        private OwnershipReader $ownership,
        private CardReader $cards,
        private AssignmentAccess $assignments,
        private VerifiedWrite $writes,
        private CardWriter $writer,
        private AssignmentEditor $assignmentEditor,
        private CardStateReader $state,
    ) {
    }

    private const EDITABLE_FIELDS = ['title', 'description', 'duedate'];
    /**
     * The payload is the updated card. When `assign` or `unassign` were sent it also reports `assigned`,
     * `unassigned` and `failed` (uid and a safe reason); a failure in the middle leaves what was already done.
     *
     * @param array<string, mixed> $arguments Requires `cardId`; accepts the editable fields, `assign`, `unassign` and `lastModified`.
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed> Operation payload; the tool adds the MCP result envelope.
     * @throws InvalidArgumentException When nothing editable is present, a date is invalid or an assignee is refused, mapped to -32602.
     */
    public function execute(array $arguments, string $userId): array {
        $formatter = $this->formatters->forUser($userId);
        $given = array_intersect_key($arguments, array_flip(self::EDITABLE_FIELDS));
        $changes = AssignmentChanges::fromArguments($arguments);
        $editsAssignees = $changes['assign'] !== [] || $changes['unassign'] !== [];
        if ($given === [] && !$editsAssignees) {
            throw new InvalidArgumentException(ErrorMessages::errorNoFieldToEdit());
        }
        // Owned by somebody else and not confirmed yet: answer with the shared-resource payload
        // and leave the card untouched, before it is even read.
        $confirmation = SharedBoardConfirmation::confirmShared($arguments, $userId, $this->ownership->cardOwnership($userId, (int) $arguments['cardId']));
        if ($confirmation !== null) {
            return $confirmation;
        }
        $card = $this->cards->findCard($userId, (int) $arguments['cardId']);
        $expected = $arguments['lastModified'] ?? null;
        if ($expected !== null && (int) $expected !== $card->getLastModified()) {
            throw new DeckConflictException(ErrorMessages::errorConflict());
        }
        // Everything that can be refused is refused here, before the first write.
        $title = array_key_exists('title', $given) ? CardInput::requireTitle((string) $given['title']) : (string) $card->getTitle();
        $description = array_key_exists('description', $given) ? CardInput::requireDescription((string) $given['description']) : (string) $card->getDescription();
        $duedate = array_key_exists('duedate', $given) ? CardInput::duedate($given['duedate']) : $this->currentDuedate($card->getDuedate());
        $resolved = AssignmentChanges::resolve($changes['assign'], $changes['unassign'], AssignedUsers::assignedUids($card));
        if ($resolved['add'] !== []) {
            $this->assignments->validateAssignees($userId, (int) $card->getStackId(), $resolved['add']);
        }
        $warnings = [];
        if ($given === []) {
            $updated = $card;
        } else {
            $updated = $this->writes->afterWrite(
                'deck_edit_card',
                'deck_read_card',
                fn() => $this->writer->updateCard($userId, $card, $title, $description, $duedate),
                fn() => $this->savedEdit($userId, $card, $title, $description, $duedate),
                $warnings,
            );
            // The update answer does not always carry the assignments; the ones read above still stand.
            if ($updated->getAssignedUsers() === null) {
                $updated->setAssignedUsers($card->getAssignedUsers() ?? []);
            }
        }
        if (!$editsAssignees) {
            return $formatter->card($updated) + ($warnings === [] ? [] : ['warnings' => $warnings]);
        }
        // Applied first: the card is formatted from the assignments that really stand afterwards.
        $report = $this->assignmentEditor->applyAssignees($userId, $updated, $resolved, $warnings);
        return $formatter->card($updated) + $report;
    }

    /**
     * The card read back after an update that threw, when it shows the update: the fields asked for and a new
     * modification time (Deck's mapper sets it on every update), so asking for the values the card already had never
     * passes for a write.
     *
     * @param string $userId UID of the authenticated caller.
     * @param Card $before Card as read before the update.
     * @param string $title Title sent.
     * @param string $description Description sent.
     * @param string|null $duedate Due date sent; only whether there is one is compared, since Deck stores the instant
     *     in the caller's timezone.
     * @return Card|null The saved card, or null when the update did not happen.
     */
    private function savedEdit(
        string $userId,
        Card $before,
        string $title,
        string $description,
        ?string $duedate,
    ): ?Card {
        $card = $this->state->cardState($userId, (int) $before->getId());
        $saved = (string) $card->getTitle() === $title && self::withoutAstral((string) $card->getDescription()) === self::withoutAstral($description) && ($card->getDuedate() === null) === ($duedate === null) && (int) $card->getLastModified() !== (int) $before->getLastModified();
        return $saved ? $card : null;
    }

    /**
     * A description as a database without 4-byte UTF-8 stores it: Deck's mapper replaces those characters with U+FFFD.
     *
     * @param string $text Description.
     * @return string The same text with every character outside the Basic Multilingual Plane replaced.
     */
    private static function withoutAstral(string $text): string {
        return (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', "�", $text);
    }

    /**
     * Current due date of a card, kept to the second.
     *
     * Sending back only the day would reset the time set in the Deck web interface to midnight on
     * every edit of the title or description, so the full instant goes back unchanged.
     *
     * @param mixed $duedate Value of `Card::getDuedate()`.
     * @return string|null ISO 8601 timestamp, or null when the card has no date.
     */
    private function currentDuedate(mixed $duedate): ?string {
        return $duedate instanceof \DateTimeInterface ? $duedate->format(\DateTimeInterface::ATOM) : null;
    }
}
