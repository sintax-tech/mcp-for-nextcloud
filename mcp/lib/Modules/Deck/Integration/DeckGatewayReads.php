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
use OCP\Files\File;
use OCP\Share\IShare;

/** The unchanged Deck queries of the composed gateway. */
interface DeckGatewayReads {
	/**
	 * Boards shared with the user, archived ones excluded.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return list<Board> Boards visible to the caller, unsorted.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	public function listBoards(string $userId): array;
	/**
	 * Fresh editable origin, readable active target and the bounded readable cycle-check graph.
	 * @param string $userId Authenticated caller.
	 * @param int $originId Origin whose outgoing relation changes.
	 * @param int $targetId Target requiring READ even for removal.
	 * @param bool $assign True for addition.
	 * @param bool $checkCycle False only for read-back after an attempted write.
	 * @return array<string, mixed> Only readable state; never hidden IDs, counts or timestamps.
	 * @throws \Throwable On denial, uncertainty, self-relation or detectable cycle.
	 */
	public function dependencyChangeState(string $userId, int $originId, int $targetId, bool $assign,
		bool $checkCycle = true): array;
	/**
	 * Fresh native parent context for file reads/writes; no native attachment metadata/count is returned.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Source card.
	 * @param bool $edit Require EDIT and active card/board for a mutation.
	 * @return array{card: Card, boardId: int, board: array} Internal authorized context.
	 * @throws \Throwable On missing/denied card, inactive parents or uncertain lookup.
	 */
	public function cardFileContext(string $userId, int $cardId, bool $edit): array;
	/**
	 * Native type-12 file shares after source READ. Internal only: callers MUST filter through Files guards.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @return list<IShare> Native associations; never serialize directly.
	 * @throws \Throwable On denied source or provider failure.
	 */
	public function cardFileShares(string $userId, int $cardId): array;
	/**
	 * One native file association after source READ and exact type/card identity checks.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @param int $attachmentId Bare share ID, distinct from fileid.
	 * @return IShare|null Internal association, null only for native ShareNotFound.
	 * @throws \Throwable For wrong-card/type, source denial or uncertain lookup.
	 */
	public function cardFileShare(string $userId, int $cardId, int $attachmentId): ?IShare;
	/**
	 * Observes a previously authorized association through the provider's unfiltered original-file query.
	 * This avoids mistaking a trash/inaccessible node (ShareNotFound in getShareById) for a deleted share.
	 * @param string $userId Authenticated caller, source READ still required.
	 * @param int $cardId Source of a previously authorized unlink attempt.
	 * @param int $attachmentId Previously authorized share ID.
	 * @param File $node Previously authorized original node; only its stable ID is used after unlink.
	 * @return bool Whether that exact association remains; no other share data leaves the gateway.
	 * @throws \Throwable On uncertain provider lookup or changed source/card binding.
	 */
	public function cardFileShareExists(string $userId, int $cardId, int $attachmentId, File $node): bool;
	/**
	 * Fresh editable card, active parents and board-local labels. Inherited foreign links remain internal.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card to read after READ+EDIT checks.
	 * @param list<int> $requested Label IDs, including removals currently absent from the card.
	 * @return array{card: Card, boardId: int, board: array, labels: list<array>, requested: list<array>, foreignAssigned: list<int>}
	 * @throws \Throwable When the caller, card, parents or requested labels are unavailable.
	 */
	public function cardLabelState(string $userId, int $cardId, array $requested): array;
	/**
	 * Labels of a readable board, with pagination applied after the READ check.
	 * @return list<\OCA\Deck\Db\Label> Labels in the native mapper order.
	 */
	public function listLabels(string $userId, int $boardId, int $limit, int $offset): array;
	/** @return DeckLabelCatalog The label catalog of boards, reads and writes, sharing this gateway's native context. */
	public function boardLabels(): DeckLabelCatalog;
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
	 * a plain card costs no query of its own. Native references, when present, require fresh target
	 * checks. At most 100 visible boards are examined, and
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
	 * One card with references filtered for the caller; native file counts are not public.
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
