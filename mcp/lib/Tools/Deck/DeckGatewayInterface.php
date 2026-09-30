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
	 * One card, with attachments and counters enriched by the Deck.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to read.
	 * @return Card Enriched card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function findCard(string $userId, int $cardId): Card;

	/**
	 * Creates a card owned by the caller at the end of the stack.
	 *
	 * @param string $userId UID of the authenticated caller, used as the card owner.
	 * @param int $stackId Stack that receives the card.
	 * @param string $title Card title.
	 * @param string $description Card description, possibly empty.
	 * @param string|null $duedate Due date as `YYYY-MM-DD`, or null for no date.
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
	 * @param string|null $duedate New due date as `YYYY-MM-DD`, or null to clear it.
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
}
