<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Modules\Deck\Integration\DeckErrors;
use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Failures\DeckUnconfirmedException;

/** Deletes an empty list or an empty board and restores it when a card arrives during the delete. */
final class DeckUndo {
	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param DeckCardCounts $counts Second card count, without a new permission check or a new session bind.
	 * @param DeckOwnership $ownership Owner check taken before any card of a board is counted.
	 * @param DeckBoards $boards Boards the caller owns, reread after a failed undo.
	 * @param DeckStacks $stacks Manage count before a list delete, and the list reread from the trash.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private DeckCardCounts $counts,
		private DeckOwnership $ownership,
		private DeckBoards $boards,
		private DeckStacks $stacks
	) {
	}

	/**
	 * Soft deletes a list, but only while it holds no card.
	 *
	 * Deck itself deletes a list whatever it holds, so the cards are counted again here, right before the
	 * delete, and a card that appeared since the plan was shown stops it. They are counted once more after the
	 * delete: a card created in between brings the list back (Deck has no undo for a list, so it is updated with
	 * `deletedAt: 0`) and the call is refused. A restore that throws is read again before any state is claimed.
	 *
	 * Residual window, accepted: a card created in the instant right after the second count may still go to the
	 * trash with the list, where it is recoverable.
	 *
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
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId List to delete.
	 * @return Stack Deleted list, still recoverable in Deck.
	 * @throws DeckRefusalException When the list holds any card, before or during the delete; when the restore itself
	 *     failed the message says the list is in the Deck trash.
	 * @throws DeckUnconfirmedException When a restore or undo threw and the state cannot be read again.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::deleteEmptyStack()
	 */
	public function deleteEmptyStack(string $userId, int $stackId): Stack {
		// The count runs the manage check and binds the caller; it is taken again here and not trusted from the plan.
		$cards = $this->stacks->stackCardCount($userId, $stackId);
		if ($cards > 0) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::stackNotEmpty($cards);
		}

		/** @var StackService $stackService */
		$stackService = $this->services->get(StackService::class);

		// Soft delete: Deck stamps `deleted_at` on the list and keeps the row.
		$stack = $stackService->delete($stackId);
		if ($this->counts->cardsInStack($stackId) > 0) {
			$this->restoreStack($userId, $stackService, $stack);
		}

		return $stack;
	}

	/**
	 * Soft deletes a board, but only for its owner and only while none of its lists holds a card.
	 *
	 * Same residual window as {@see self::deleteEmptyStack()}, accepted: a card created in the instant right after the
	 * second count may go to the trash with the board, where it is recoverable.
	 *
	 * The board is counted again after the delete and, when a card showed up in one of its lists, brought back with
	 * Deck's own `BoardService::deleteUndo()` (it clears `deleted_at` and writes the restore activity).
	 *
	 * @param string $userId UID of the authenticated caller, who must own the board.
	 * @param int $boardId Board to delete.
	 * @return Board Deleted board, still recoverable in Deck.
	 * @throws DeckRefusalException When the caller is not the owner or any list holds a card, before or during the
	 *     delete (the delete is then undone with `BoardService::deleteUndo()`; if that fails the message says the board
	 *     is in the Deck trash).
	 * @throws DeckUnconfirmedException When a restore or undo threw and the state cannot be read again.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::deleteEmptyBoard()
	 */
	public function deleteEmptyBoard(string $userId, int $boardId): Board {
		// Owner first: somebody who merely manages the board is refused before a single card is counted.
		if ($this->ownership->boardOwnership($userId, $boardId)['owner'] !== $userId) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardNotOwned();
		}
		$cards = $this->counts->cardsInBoard($boardId);
		if ($cards > 0) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardNotEmpty($cards);
		}

		/** @var BoardService $boardService */
		$boardService = $this->services->get(BoardService::class);

		// Soft delete: Deck stamps `deleted_at` on the board, which leaves the lists of the web interface and
		// can be brought back from the Deck deleted items.
		$board = $boardService->delete($boardId);
		if ($this->counts->cardsInBoard($boardId) > 0) {
			$this->undoBoardDelete($userId, $boardService, $boardId);
		}

		return $board;
	}

	/**
	 * Brings a list back after a card arrived during its deletion, and refuses the call either way.
	 *
	 * `StackService::update()` writes `deleted_at = 0` before it records activity and dispatches events, so an
	 * exception of the restore does not prove that the list stayed in the trash: the list is read again
	 * ({@see DeckStacks::deletedStack()}) and only a list that is still in the trash is reported as not restored.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param StackService $stackService Deck list service, already bound to the caller.
	 * @param Stack $deleted The list as `StackService::delete()` returned it.
	 * @return never
	 * @throws DeckRefusalException Always: "nothing was deleted" when the list is back, a message that points to the
	 *     Deck trash when it is still there (no Deck detail is carried).
	 * @throws DeckUnconfirmedException When the restore threw and the list cannot be read again.
	 */
	private function restoreStack(string $userId, StackService $stackService, Stack $deleted): never {
		try {
			$stackService->update((int)$deleted->getId(), (string)$deleted->getTitle(), (int)$deleted->getBoardId(), (int)$deleted->getOrder(), 0);
		} catch (\Throwable $e) {
			try {
				$stillDeleted = $this->stacks->deletedStack($userId, (int)$deleted->getId()) !== null;
			} catch (\Throwable) {
				throw new \OCA\Mcp\Modules\Deck\Failures\DeckUnconfirmedException('deck_list_stacks', $e);
			}

			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::stackReceivedCards(!$stillDeleted);
		}

		throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::stackReceivedCards(true);
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
	private function undoBoardDelete(string $userId, BoardService $boardService, int $boardId): never {
		try {
			$boardService->deleteUndo($boardId);
		} catch (\Throwable $e) {
			try {
				$stillDeleted = null;
				foreach ($this->boards->ownedBoards($userId) as $board) {
					if ((int)$board->getId() === $boardId) {
						$stillDeleted = (int)$board->getDeletedAt() !== 0;
					}
				}
				if ($stillDeleted === null) {
					throw new \RuntimeException('The board is not among the boards of the caller');
				}
			} catch (\Throwable) {
				throw new \OCA\Mcp\Modules\Deck\Failures\DeckUnconfirmedException('deck_list_boards', $e);
			}

			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardReceivedCards(!$stillDeleted);
		}

		throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardReceivedCards(true);
	}
}
