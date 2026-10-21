<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;

class StructureStateReader {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): BoardMapper $boardMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $boardMapperProvider,
    ) {
    }

    /**
     * The active lists of a board, read again from the database (deleted ones left out, no cards embedded).
     *
     * @param string $userId UID of the authenticated caller, who must be able to read the board.
     * @param int $boardId Board to read.
     * @return list<Stack> Its lists.
     * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
     */
    public function stacksOf(string $userId, int $boardId): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        $permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);
        return array_values($stackMapper->findAll($boardId));
    }

    /**
     * `StackMapper::find()` keeps the entity of the request in memory, with the `deleted_at` the failed delete set on
     * it even when the update never reached the database, so the lists are queried again instead.
     */
    public function deletedStack(string $userId, int $stackId): ?Stack {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        $boardId = $stackMapper->findBoardId($stackId) ?? throw new NoPermissionException('Permission denied');
        $permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);
        foreach ($stackMapper->findDeleted($boardId) as $stack) {
            if ((int) $stack->getId() === $stackId) {
                return $stack;
            }
        }
        foreach ($stackMapper->findAll($boardId) as $stack) {
            if ((int) $stack->getId() === $stackId) {
                return null;
            }
        }
        throw new \RuntimeException('The list is neither active nor in the trash');
    }

    /**
     * `BoardMapper::findAllByOwner()` is a fresh query; `find()` would answer from the entity cache of the request.
     * Only the caller's own boards are read, so no ACL check is needed.
     */
    public function ownedBoards(string $userId): array {
        $this->caller->bind($userId);
        /** @var BoardMapper $boardMapper */
        $boardMapper = ($this->boardMapperProvider)();
        return array_values($boardMapper->findAllByOwner($userId) ?: []);
    }

    /**
     * The board a creation that threw saved: the newest active board of the caller with that title that did not exist before.
     *
     * @param string $userId UID of the authenticated caller.
     * @param string $title Title asked for.
     * @param list<int> $before Ids of the caller's boards before the write.
     * @return \OCA\Deck\Db\Board|null The board, or null when there is none.
     */
    public function newBoard(
        string $userId,
        string $title,
        array $before,
    ): ?Board {
        $found = null;
        foreach ($this->ownedBoards($userId) as $board) {
            if (!in_array((int) $board->getId(), $before, true) && (string) $board->getTitle() === $title && (int) $board->getDeletedAt() === 0 && ($found === null || (int) $board->getId() > (int) $found->getId())) {
                $found = $board;
            }
        }
        return $found;
    }

    /**
     * The list a creation added, among the lists read again after it threw.
     *
     * @param list<Stack> $stacks Active lists of the board now.
     * @param string $title Title of the list asked for.
     * @param list<int> $known Ids that existed before, or were created earlier in the same call.
     * @return Stack|null The newest list with that title that is not one of `$known`, or null.
     */
    public static function newStack(
        array $stacks,
        string $title,
        array $known,
    ): ?Stack {
        $found = null;
        foreach ($stacks as $stack) {
            if (!in_array((int) $stack->getId(), $known, true) && (string) $stack->getTitle() === $title && ($found === null || (int) $stack->getId() > (int) $found->getId())) {
                $found = $stack;
            }
        }
        return $found;
    }
}
