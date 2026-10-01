<?php
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
	 * @return Card Created card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function createCard(string $userId, int $stackId, string $title, string $description, ?string $duedate): Card;

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
}
