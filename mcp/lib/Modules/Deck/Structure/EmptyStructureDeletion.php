<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Execution\DeckUnconfirmedException;

class EmptyStructureDeletion {
    /** @param \Closure(): StackService $stackServiceProvider
     * @param \Closure(): BoardService $boardServiceProvider */
    public function __construct(
        private DeckCaller $caller,
        private StructureCardCounter $counts,
        private StructureStateReader $structureState,
        private \Closure $stackServiceProvider,
        private \Closure $boardServiceProvider,
    ) {
    }

    /**
     * Deck's `StackService::delete()` never looks at the cards, so a card created by another request after the count
     * would land in the trash with the list. The list is therefore counted again after the delete and, when a card
     * showed up, restored. Deck 1.17.5 has no undo for a list, but its web interface brings one back by calling
     * `StackService::update()` with `deletedAt: 0`; that path checks MANAGE and writes the activity, the change
     * tracking and the board event, so the restore leaves the same traces a user restore would.
     *
     * A database transaction around count and delete was considered and rejected: it holds no lock on the cards
     * table, so a concurrent insert is not blocked, and on MySQL's default REPEATABLE READ the second count would
     * not even see it. It would also run Deck's notifications inside a transaction that may be rolled back. What
     * remains is a window of a few milliseconds, between the recount and a request that had already passed Deck's
     * checks (`CardService::create()` never looks at the `deleted_at` of the list); a card created in the instant right
     * after the second count sits in the trashed list, which stays recoverable from the Deck trash. This residual
     * window is accepted (third round of the review): closing it would need a lock shared with every card write of
     * the Deck app, which this app cannot take, and the tool guide says so.
     */
    public function deleteEmptyStack(string $userId, int $stackId): Stack {
        // The count runs the manage check and binds the caller; it is taken again here and not trusted from the plan.
        $recount = $this->counts->forManagedStack($userId, $stackId);
        $cards = $recount();
        if ($cards > 0) {
            throw DeckRefusalException::stackNotEmpty($cards);
        }
        /** @var StackService $stackService */
        $stackService = ($this->stackServiceProvider)();
        // Soft delete: Deck stamps `deleted_at` on the list and keeps the row.
        $stack = $stackService->delete($stackId);
        if ($recount() > 0) {
            $this->restoreStack($userId, $stackService, $stack);
        }
        return $stack;
    }

    /**
     * The board is counted again after the delete and, when a card showed up in one of its lists, brought back with
     * Deck's own `BoardService::deleteUndo()` (it clears `deleted_at` and writes the restore activity).
     *
     * The same residual window as {@see self::deleteEmptyStack()} remains and is accepted: a card created in the
     * instant right after the second count may go to the trash with the board, where it is recoverable.
     */
    public function deleteEmptyBoard(string $userId, int $boardId): Board {
        // Owner first: somebody who merely manages the board is refused before a single card is counted.
        $recount = $this->counts->forOwnedBoard($userId, $boardId);
        $cards = $recount();
        if ($cards > 0) {
            throw DeckRefusalException::boardNotEmpty($cards);
        }
        /** @var BoardService $boardService */
        $boardService = ($this->boardServiceProvider)();
        // Soft delete: Deck stamps `deleted_at` on the board, which leaves the lists of the web interface and
        // can be brought back from the Deck deleted items.
        $board = $boardService->delete($boardId);
        if ($recount() > 0) {
            $this->undoBoardDelete($userId, $boardService, $boardId);
        }
        return $board;
    }

    /**
     * Brings a list back after a card arrived during its deletion, and refuses the call either way.
     *
     * `StackService::update()` writes `deleted_at = 0` before it records activity and dispatches events, so an
     * exception of the restore does not prove that the list stayed in the trash: the list is read again
     * ({@see self::deletedStack()}) and only a list that is still in the trash is reported as not restored.
     *
     * @param string $userId UID of the authenticated caller.
     * @param StackService $stackService Deck list service, already bound to the caller.
     * @param Stack $deleted The list as `StackService::delete()` returned it.
     * @return never
     * @throws DeckRefusalException Always: "nothing was deleted" when the list is back, a message that points to the
     *     Deck trash when it is still there (no Deck detail is carried).
     * @throws DeckUnconfirmedException When the restore threw and the list cannot be read again.
     */
    private function restoreStack(
        string $userId,
        StackService $stackService,
        Stack $deleted,
    ): never {
        try {
            $stackService->update(
                (int) $deleted->getId(),
                (string) $deleted->getTitle(),
                (int) $deleted->getBoardId(),
                (int) $deleted->getOrder(),
                0,
            );
        } catch (\Throwable $e) {
            try {
                $stillDeleted = $this->structureState->deletedStack($userId, (int) $deleted->getId()) !== null;
            } catch (\Throwable) {
                throw new DeckUnconfirmedException('deck_list_stacks', $e);
            }
            throw DeckRefusalException::stackReceivedCards(!$stillDeleted);
        }
        throw DeckRefusalException::stackReceivedCards(true);
    }

    /**
     * Brings a board back after a card arrived during its deletion, and refuses the call either way.
     *
     * `BoardService::deleteUndo()` writes `deleted_at = 0` before its activity and events, so after an exception the
     * board is read again among the caller's own boards and only one still in the trash is reported as not restored.
     *
     * @param string $userId UID of the authenticated caller, the owner.
     * @param BoardService $boardService Deck board service, already bound to the caller.
     * @param int $boardId Board that was just deleted.
     * @return never
     * @throws DeckRefusalException Always: "nothing was deleted" when the board is back, a message that points to the
     *     Deck trash when it is still there (no Deck detail is carried).
     * @throws DeckUnconfirmedException When the undo threw and the board cannot be read again.
     */
    private function undoBoardDelete(
        string $userId,
        BoardService $boardService,
        int $boardId,
    ): never {
        try {
            $boardService->deleteUndo($boardId);
        } catch (\Throwable $e) {
            try {
                $stillDeleted = null;
                foreach ($this->structureState->ownedBoards($userId) as $board) {
                    if ((int) $board->getId() === $boardId) {
                        $stillDeleted = (int) $board->getDeletedAt() !== 0;
                    }
                }
                if ($stillDeleted === null) {
                    throw new \RuntimeException('The board is not among the boards of the caller');
                }
            } catch (\Throwable) {
                throw new DeckUnconfirmedException('deck_list_boards', $e);
            }
            throw DeckRefusalException::boardReceivedCards(!$stillDeleted);
        }
        throw DeckRefusalException::boardReceivedCards(true);
    }
}
