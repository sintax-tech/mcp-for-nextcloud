<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Assignments;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\PermissionService;
use OCP\IUserManager;

class AssignmentAccess {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): IUserManager $iUserManagerProvider
     * @param \Closure(): AssignmentService $assignmentServiceProvider
     * @param \Closure(): AssignmentMapper $assignmentMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $iUserManagerProvider,
        private \Closure $assignmentServiceProvider,
        private \Closure $assignmentMapperProvider,
        private \Closure $cardMapperProvider,
    ) {
    }

    /** {@inheritDoc} */
    public function validateAssignees(
        string $userId,
        int $stackId,
        array $assignees,
    ): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissions */
        $permissions = ($this->permissionServiceProvider)();
        /** @var StackMapper $stacks */
        $stacks = ($this->stackMapperProvider)();
        $permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_EDIT);
        $boardId = $stacks->findBoardId($stackId);
        /** @var IUserManager $users */
        $users = ($this->iUserManagerProvider)();
        $out = [];
        foreach (AssignedUsers::assignees($assignees) as $uid) {
            $user = $users->get($uid);
            // Deck evaluates owner, direct ACL, group and circle membership for this account.
            if ($user === null || !($permissions->getPermissions($boardId, $uid)[Acl::PERMISSION_READ] ?? false)) {
                throw new ArgumentValidationException(
                    'Invalid argument: assignees',
                    'assignees',
                    Translator::t('each account must exist and have access to the board'),
                );
            }
            $out[] = ['uid' => $uid, 'displayName' => $user->getDisplayName()];
        }
        return $out;
    }

    /** {@inheritDoc} */
    public function assignCardUser(
        string $userId,
        int $cardId,
        string $assignee,
    ): Assignment {
        $this->caller->bind($userId);
        /** @var AssignmentService $assignments */
        $assignments = ($this->assignmentServiceProvider)();
        return $assignments->assignUser($cardId, $assignee);
    }

    /** {@inheritDoc} */
    public function unassignCardUser(
        string $userId,
        int $cardId,
        string $assignee,
    ): Assignment {
        $this->caller->bind($userId);
        /** @var AssignmentService $assignments */
        $assignments = ($this->assignmentServiceProvider)();
        // Deck v1.17.5: `unassignUser(int $cardId, string $userId, int $type = 0)`; the user type is the default.
        return $assignments->unassignUser($cardId, $assignee);
    }

    /**
     * The assignments of one card, read again from the database.
     *
     * @param string $userId UID of the authenticated caller, who must be able to read the card.
     * @param int $cardId Card to read.
     * @return list<\OCA\Deck\Db\Assignment> Its assignments.
     * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
     */
    public function cardAssignments(string $userId, int $cardId): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var AssignmentMapper $assignmentMapper */
        $assignmentMapper = ($this->assignmentMapperProvider)();
        $permissionService->checkPermission(($this->cardMapperProvider)(), $cardId, Acl::PERMISSION_READ);
        return array_values($assignmentMapper->findIn([$cardId]));
    }

    /**
     * Embeds the assignments of a batch of cards, in one query for all of them.
     *
     * @param list<Card> $cards Cards of one stack, board or page, mutated in place.
     */
    public function attachAssignments(array $cards): void {
        if ($cards === []) {
            return;
        }
        /** @var AssignmentMapper $assignmentMapper */
        $assignmentMapper = ($this->assignmentMapperProvider)();
        $byCardId = [];
        foreach ($assignmentMapper->findIn(array_map(static fn(Card $card): int => $card->getId(), $cards)) as $assignment) {
            $byCardId[(int) $assignment->getCardId()][] = $assignment;
        }
        foreach ($cards as $card) {
            $card->setAssignedUsers($byCardId[$card->getId()] ?? []);
        }
    }

    /**
     * The assignment of one account on a card, read again from Deck.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card to look at.
     * @param string $uid Account assigned.
     * @return Assignment|null The user assignment of that account, or null when it has none.
     */
    public function assignmentOf(
        string $userId,
        int $cardId,
        string $uid,
    ): ?Assignment {
        foreach ($this->cardAssignments($userId, $cardId) as $assignment) {
            // Type 0 is `Assignment::TYPE_USER` (Deck v1.17.5), the only kind these tools assign.
            if ((string) $assignment->getParticipant() === $uid && (int) $assignment->getType() === 0) {
                return $assignment;
            }
        }
        return null;
    }
}
