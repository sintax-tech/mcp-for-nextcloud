<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;

/**
 * The Deck access the handlers are allowed to use.
 *
 * Handlers only ever see this interface, so they can be tested without the Deck app being
 * installed and never learn which Deck class answered. Every method performs the ACL check the
 * Deck service does internally, plus the explicit checks noted in the sprint 03 contract.
 */
interface DeckGatewayInterface {
	/**
	 * Boards shared with the user, archived ones excluded.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return list<Board> Boards visible to the caller, unsorted.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function listBoards(string $userId): array;

	/**
	 * Stacks of one board, with their cards embedded by the Deck service.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $boardId Board to list.
	 * @return list<Stack> Stacks of the board.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function listStacks(string $userId, int $boardId): array;

	/**
	 * Active cards of one stack, paginated.
	 *
	 * The Deck has no service method for this, so the gateway runs the same explicit
	 * `PERMISSION_READ` check that `StackService::findAll()` runs before touching the mapper.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Stack to list.
	 * @param int $limit Maximum number of cards.
	 * @param int $offset Number of cards to skip.
	 * @return array{items: list<Card>, boardId: int} Cards and the board they belong to.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function listCards(string $userId, int $stackId, int $limit, int $offset): array;

	/**
	 * Cards a manager follows up, from the boards that caller may manage.
	 *
	 * The deck has no such query, so the gateway walks the boards the caller may manage (owner or
	 * `PERMISSION_MANAGE`), reads their stacks and cards in batch, and filters with
	 * {@see CardCriteria} while it goes: a board costs four queries plus one permission lookup and
	 * a card never costs a query of its own. At most 100 visible boards are examined, and
	 * `truncated` reports both a scan cut short by the limit and one cut short by that board
	 * ceiling.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to manage the boards.
	 * @param string $status One of {@see CardCriteria::STATUSES}.
	 * @param int|null $boardId Restrict the scan to this board, or null for every managed board.
	 * @param string|null $assignee Keep only cards assigned to this UID, or null for any assignee.
	 * @param string|null $dueBefore Keep only cards due on or before this `YYYY-MM-DD`, or null.
	 * @param int $limit Maximum number of cards to answer with.
	 * @return array{items: list<array{card: Card, boardId: int}>, truncated: bool} Matching cards,
	 *     each with the board it came from, and whether the answer may be incomplete.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function followupCards(
		string $userId,
		string $status,
		?int $boardId,
		?string $assignee,
		?string $dueBefore,
		int $limit,
	): array;

	/**
	 * One card, with attachments and counters enriched by the Deck.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to read.
	 * @return Card Enriched card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function findCard(string $userId, int $cardId): Card;

	/**
	 * Validates accounts and their board access before a preview or creation.
	 * @param string $userId Authenticated caller.
	 * @param int $stackId Destination stack.
	 * @param list<string> $assignees Account IDs to assign.
	 * @return list<array{uid: string, displayName: string}> Unique validated accounts.
	 * @throws \InvalidArgumentException When any account is missing or lacks access.
	 */
	public function validateAssignees(string $userId, int $stackId, array $assignees): array;

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
	 * @param string|null $duedate Due date as `YYYY-MM-DD` (midnight for the caller), or null for no date.
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
	 * @param string|null $duedate New due date as `YYYY-MM-DD` (midnight for the caller), the current
	 *     ISO 8601 instant to keep it untouched, or null to clear it.
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
	 * Ownership of the board behind a stack, after the edit check the write needs.
	 *
	 * The Deck has no owner query for a stack, so the gateway checks `PERMISSION_EDIT` on the board
	 * the stack belongs to first: a stack the caller may not write is answered as "denied" before
	 * any owner or title is read, exactly like the write itself would.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $stackId Stack whose board is looked up.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function stackOwnership(string $userId, int $stackId): array;

	/**
	 * Ownership of the board behind a card, after the edit check the write needs.
	 *
	 * Same contract as {@see self::stackOwnership()}, starting from the card: the Deck checks
	 * `PERMISSION_EDIT` on the board of the card and refuses a card that is already deleted.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card whose board is looked up.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function cardOwnership(string $userId, int $cardId): array;

	/**
	 * Board a card belongs to, after the read check a link to the card needs.
	 *
	 * Talk cites a card by its canonical URL, which carries the board id; the check comes first so a
	 * caller without read access learns nothing, not even which board the card is on.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card whose board is looked up.
	 * @return int Board id.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function cardBoardId(string $userId, int $cardId): int;

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
	 * Ownership of a board, after the manage check a structure change needs.
	 *
	 * Same contract as {@see self::stackOwnership()}, starting from the board: the check comes first, so a
	 * caller who may not manage it learns neither its owner nor its title.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $boardId Board whose owner is looked up.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function boardOwnership(string $userId, int $boardId): array;

	/**
	 * Cards a list holds, active and archived (the Deck trash is not counted).
	 *
	 * @param string $userId UID of the authenticated caller, who must manage the board of the list.
	 * @param int $stackId List to count.
	 * @return int Number of cards.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function stackCardCount(string $userId, int $stackId): int;

	/**
	 * Cards a board holds in all its lists, active and archived (the Deck trash is not counted).
	 *
	 * @param string $userId UID of the authenticated caller, who must manage the board.
	 * @param int $boardId Board to count.
	 * @return int Number of cards.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function boardCardCount(string $userId, int $boardId): int;

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

	/**
	 * One card read again from the database, deleted or not, so a write tool can tell what a failed write left.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board of the card.
	 * @param int $cardId Card to read.
	 * @return Card The card as stored, without the enrichment of the Deck service.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 */
	public function cardState(string $userId, int $cardId): Card;

	/**
	 * The card a creation that threw may have saved: the newest card of the caller in that list with that title,
	 * created in the last two minutes and not one of `$excludeIds`.
	 *
	 * @param string $userId UID of the authenticated caller, the owner of the card.
	 * @param int $stackId List the card was created in.
	 * @param string $title Title of the card.
	 * @param list<int> $excludeIds Cards already accounted for, such as the ones created earlier in the same call.
	 * @return Card|null The card, or null when there is none.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 */
	public function findCreatedCard(string $userId, int $stackId, string $title, array $excludeIds = []): ?Card;

	/**
	 * The assignments of one card, read again from the database.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the card.
	 * @param int $cardId Card to read.
	 * @return list<\OCA\Deck\Db\Assignment> Its assignments.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 */
	public function cardAssignments(string $userId, int $cardId): array;

	/**
	 * The active lists of a board, read again from the database (deleted ones left out, no cards embedded).
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board.
	 * @param int $boardId Board to read.
	 * @return list<Stack> Its lists.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 */
	public function stacksOf(string $userId, int $boardId): array;

	/**
	 * Whether a list went to the Deck trash, read again from the database.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board of the list.
	 * @param int $stackId List to look for.
	 * @return Stack|null The list as found in the trash, or null when it is still among the active lists.
	 * @throws \Throwable When it is found nowhere, or any Deck failure; the caller treats it as "cannot tell".
	 */
	public function deletedStack(string $userId, int $stackId): ?Stack;

	/**
	 * The boards the caller owns, read again from the database, deleted ones included.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return list<Board> Their boards.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 */
	public function ownedBoards(string $userId): array;
}
