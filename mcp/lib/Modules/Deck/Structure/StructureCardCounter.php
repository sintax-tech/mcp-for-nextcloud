<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;

class StructureCardCounter {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private OwnershipReader $ownership,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
    ) {
    }

    /**
     * Cards a list holds, active and archived (the Deck trash is not counted).
     *
     * @param string $userId UID of the authenticated caller, who must manage the board of the list.
     * @param int $stackId List to count.
     * @return int Number of cards.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function stackCardCount(string $userId, int $stackId): int {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        // The same check `StackService::delete()` runs, taken before any card is read.
        $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_MANAGE);
        return $this->cardsInStack($stackId);
    }

    /**
     * Cards a board holds in all its lists, active and archived (the Deck trash is not counted).
     *
     * @param string $userId UID of the authenticated caller, who must manage the board.
     * @param int $boardId Board to count.
     * @return int Number of cards.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function boardCardCount(string $userId, int $boardId): int {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        $permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
        return $this->cardsInBoard($boardId);
    }

    /**
     * Cards of one list that still count as cards: the active ones and the archived ones.
     *
     * Deck leaves the deleted cards (its trash) out of both queries, and a card in the trash is not a reason
     * to keep a list.
     *
     * @param int $stackId List to count; the caller was already checked.
     * @return int Number of cards.
     */
    private function cardsInStack(int $stackId): int {
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        return count($cardMapper->findAll($stackId) ?: []) + count($cardMapper->findAllArchived($stackId) ?: []);
    }

    /**
     * Cards of every list of a board, counted as {@see self::cardsInStack()} does.
     *
     * @param int $boardId Board to count; the caller was already checked.
     * @return int Number of cards.
     */
    private function cardsInBoard(int $boardId): int {
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        $cards = 0;
        foreach ($stackMapper->findAll($boardId) as $stack) {
            $cards += $this->cardsInStack((int) $stack->getId());
        }
        return $cards;
    }

    /** Returns a counter bound to one authorized list, valid during its soft-delete recovery. @return \Closure(): int */
    public function forManagedStack(string $userId, int $stackId): \Closure {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        // The same check `StackService::delete()` runs, taken before any card is read.
        $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_MANAGE);
        return fn(): int => $this->cardsInStack($stackId);
    }

    /** Returns a counter bound to one owned board, valid during its soft-delete recovery. @return \Closure(): int */
    public function forOwnedBoard(string $userId, int $boardId): \Closure {
        if ($this->ownership->boardOwnership($userId, $boardId)['owner'] !== $userId) {
            throw DeckRefusalException::boardNotOwned();
        }
        return fn(): int => $this->cardsInBoard($boardId);
    }
}
