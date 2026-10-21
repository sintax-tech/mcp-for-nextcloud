<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Reading\CardReader;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentPlan;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentChanges;
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;
use OCA\Mcp\Modules\Deck\Cards\CardInput;
use InvalidArgumentException;

class EditCardPlan {
    public function __construct(
        private CardReader $cards,
        private AssignmentPlan $assignmentPlan,
        private OwnershipReader $ownership,
        private ?UserTimezone $zones = null,
    ) {
    }

    /**
     * @param array{cardId: int, title?: string, description?: string, duedate?: string|null, assign?: list<string>, unassign?: list<string>, lastModified?: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     */
    public function build(array $arguments, string $userId): array {
        $given = array_intersect_key($arguments, array_flip(['title', 'description', 'duedate']));
        $changes = AssignmentChanges::fromArguments($arguments);
        $editsAssignees = $changes['assign'] !== [] || $changes['unassign'] !== [];
        if ($given === [] && !$editsAssignees) {
            throw new InvalidArgumentException(ErrorMessages::errorNoFieldToEdit());
        }
        $card = $this->cards->findCard($userId, (int) $arguments['cardId']);
        // Both dates are instants in the caller's zone, the day the write turns into local midnight
        // included, so the same date is never reported as a change only because it is spelled otherwise.
        $zone = $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
        $before = CardDate::fields($card, $zone);
        $after = [
            'title' => array_key_exists('title', $given) ? CardInput::requireTitle((string) $given['title']) : $before['title'],
            'description' => array_key_exists('description', $given) ? CardInput::requireDescription((string) $given['description']) : $before['description'],
            // Absent keeps the date as it is; an explicit null clears it, exactly as the write reads it.
            'duedate' => array_key_exists('duedate', $given) ? CardDate::day(CardInput::duedate($given['duedate']), $zone) : $before['duedate'],
        ];
        $assignees = $editsAssignees ? $this->assignmentPlan->build($userId, $card, $changes) : null;
        $board = $this->ownership->cardOwnership($userId, (int) $arguments['cardId']);
        $changed = array_values(array_filter(
            ['title', 'description', 'duedate'],
            static fn(string $field): bool => $before[$field] !== $after[$field],
        ));
        if ($assignees !== null && ($assignees['added'] !== [] || $assignees['removed'] !== [])) {
            $changed[] = 'assignees';
        }
        return [
            'action' => 'deck_edit_card',
            'card' => ['id' => (int) $card->getId(), 'before' => $before, 'after' => $after],
            'board' => PlanLocation::board($board, $userId),
            'changed' => $changed,
            'lastModified' => ['current' => (int) $card->getLastModified(), 'sent' => $arguments['lastModified'] ?? null],
            'shared' => $board['owner'] === $userId ? [] : [PlanLocation::board($board, $userId)],
            'recoverable' => true,
            'message' => CardMessages::planEdit(),
        ] + ($assignees === null ? [] : ['assignees' => $assignees]);
    }
}
