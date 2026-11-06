<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;

/** The unchanged Deck writes of the composed gateway. */
interface DeckGatewayWrites {
	/**
	 * Revalidates the complete readable snapshot immediately before one native association write.
	 * @param string $userId Authenticated caller.
	 * @param int $originId Editable origin.
	 * @param int $targetId Readable target.
	 * @param bool $assign Add when true; otherwise remove only this relation.
	 * @param array<string, mixed> $expected Canonical snapshot shown in the plan.
	 * @return void
	 * @throws DeckConflictException Before writing when the readable snapshot changed.
	 * @throws \Throwable On refusal or native failure; persistence requires read-back.
	 */
	public function changeDependency(string $userId, int $originId, int $targetId, bool $assign, array $expected): void;

	/**
	 * Revalidates native source and association identity, then uses AttachmentService::delete(..., 'file').
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Editable active source.
	 * @param int $attachmentId Share ID of the visible file association.
	 * @param array<string, mixed> $expected Expected identity from an authorized Files state.
	 * @return void
	 * @throws DeckConflictException Before the native API when identity changed.
	 * @throws \Throwable On denial or native error, possibly after persistence.
	 */
	public function unlinkCardFile(string $userId, int $cardId, int $attachmentId, array $expected): void;

	/**
	 * Rechecks the snapshot and ACL before one native incremental association write.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card to edit.
	 * @param int $labelId One requested board-local label.
	 * @param bool $assign True to assign; false to remove only this link.
	 * @param list<int> $requested All requested IDs to revalidate.
	 * @param array<string, mixed> $expected Last observed canonical snapshot.
	 * @return void
	 * @throws DeckConflictException Before any native write when the snapshot differs.
	 * @throws \Throwable When validation or the native operation fails; the handler reads state again.
	 */
	public function changeCardLabel(string $userId, int $cardId, int $labelId, bool $assign,
		array $requested, array $expected): void;

	/**
	 * Assigns one validated account through Deck's assignment service.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Created card.
	 * @param string $assignee Account ID to assign.
	 * @return \OCA\Deck\Db\Assignment Persisted assignment.
	 * @throws \Throwable When a concurrent change prevents assignment.
	 */
	public function assignCardUser(string $userId, int $cardId, string $assignee): \OCA\Deck\Db\Assignment;

	/**
	 * Removes one account from the assignees of a card through Deck's assignment service.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card the account leaves.
	 * @param string $assignee Account ID to unassign.
	 * @return \OCA\Deck\Db\Assignment Removed assignment.
	 * @throws \Throwable When Deck refuses or a concurrent change prevents it.
	 */
	public function unassignCardUser(string $userId, int $cardId, string $assignee): \OCA\Deck\Db\Assignment;

	/**
	 * Creates a card owned by the caller at the end of the stack.
	 *
	 * @param string $userId UID of the authenticated caller, used as the card owner.
	 * @param int $stackId Stack that receives the card.
	 * @param string $title Card title.
	 * @param string $description Card description, possibly empty.
	 * @param string|null $duedate Calendar day, account-local time or offset instant; normalized to UTC Z for Deck, or null.
	 * @param int|null $order Position in the stack, or null to go after the last card.
	 * @return Card Created card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate, ?int $order = null): Card;

	/**
	 * Writes the full card form, as `CardService::update()` requires it.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param Card $card Card as previously read, source of the fields the caller did not change.
	 * @param string $title New title.
	 * @param string $description New description.
	 * @param string|null $duedate Calendar day, account-local time or offset instant (including the current instant),
	 *     normalized to UTC Z for Deck, or null to clear it.
	 * @return Card Updated card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function updateCard(string $userId, Card $card, string $title, string $description, ?string $duedate): Card;

	/**
	 * Moves a card to another stack, possibly of another board.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to move.
	 * @param int $stackId Destination stack.
	 * @param int|null $order Target position, or null to append at the end of the destination.
	 * @return Card Moved card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function moveCard(string $userId, int $cardId, int $stackId, ?int $order): Card;

	/**
	 * Soft deletes a card, exactly as the Deck web interface does.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to delete.
	 * @return Card Deleted card, still readable by the Deck.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function deleteCard(string $userId, int $cardId): Card;

	/**
	 * Creates a board owned by the caller, with the default labels Deck gives every new board.
	 *
	 * @param string $userId UID of the authenticated caller, the owner of the new board.
	 * @param string $title Board title, 1 to 100 characters.
	 * @param string $color Six hexadecimal digits, without `#`.
	 * @return Board Created board.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function createBoard(string $userId, string $title, string $color): Board;

	/**
	 * Creates a list on a board the caller manages.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $boardId Board that receives the list.
	 * @param string $title List title.
	 * @param int|null $order Position among the lists, or null to go after the last one.
	 * @return Stack Created list.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function createStack(string $userId, int $boardId, string $title, ?int $order): Stack;

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
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId List to delete.
	 * @return Stack Deleted list, still recoverable in Deck.
	 * @throws DeckRefusalException When the list holds any card, before or during the delete; when the restore itself
	 *     failed the message says the list is in the Deck trash.
	 * @throws DeckUnconfirmedException When a restore or undo threw and the state cannot be read again.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function deleteEmptyStack(string $userId, int $stackId): Stack;

	/**
	 * Soft deletes a board, but only for its owner and only while none of its lists holds a card.
	 *
	 * Same residual window as {@see self::deleteEmptyStack()}, accepted: a card created in the instant right after the
	 * second count may go to the trash with the board, where it is recoverable.
	 *
	 * @param string $userId UID of the authenticated caller, who must own the board.
	 * @param int $boardId Board to delete.
	 * @return Board Deleted board, still recoverable in Deck.
	 * @throws DeckRefusalException When the caller is not the owner or any list holds a card, before or during the
	 *     delete (the delete is then undone with `BoardService::deleteUndo()`; if that fails the message says the board
	 *     is in the Deck trash).
	 * @throws DeckUnconfirmedException When a restore or undo threw and the state cannot be read again.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function deleteEmptyBoard(string $userId, int $boardId): Board;
}
