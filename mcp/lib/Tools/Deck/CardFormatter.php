<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;

/**
 * Converts Deck entities into the plain arrays returned by the Deck tools.
 *
 * Only fields the contract lists are exposed; Deck internals such as `shareToken`, ACL rows or
 * physical storage paths are never copied into the response.
 */
final class CardFormatter {
	/** Duedate is a Deck `datetime` column; the tools speak plain `YYYY-MM-DD`. */
	private const DATE_FORMAT = 'Y-m-d';

	/**
	 * @param Board $board Board as returned by `BoardService::findAll()`.
	 * @return array{id: int, title: string, color: string|null, archived: bool, owner: string, lastModified: int}
	 */
	public function board(Board $board): array {
		return [
			'id' => $board->getId(),
			'title' => $board->getTitle(),
			'color' => $board->getColor(),
			'archived' => (bool)$board->getArchived(),
			'owner' => $board->getOwner(),
			'lastModified' => $board->getLastModified(),
		];
	}

	/**
	 * @param Stack $stack Stack as returned by `StackService::findAll()`, cards already embedded.
	 * @return array{id: int, boardId: int, title: string, order: int, cardsCount: int}
	 */
	public function stack(Stack $stack): array {
		return [
			'id' => $stack->getId(),
			'boardId' => $stack->getBoardId(),
			'title' => $stack->getTitle(),
			'order' => $stack->getOrder(),
			'cardsCount' => count($stack->getCards() ?? []),
		];
	}

	/**
	 * @param Card $card Card as returned by the Deck services.
	 * @param int|null $boardId Board the card belongs to, resolved once by the gateway for listings.
	 * @return array{id: int, stackId: int, boardId: int|null, title: string, description: string, type: string, owner: string, order: int, archived: bool, done: int|null, duedate: string|null, lastModified: int, attachmentCount: int|null}
	 */
	public function card(Card $card, ?int $boardId = null): array {
		return [
			'id' => $card->getId(),
			'stackId' => $card->getStackId(),
			'boardId' => $boardId ?? $card->getRelatedBoard()?->getId(),
			'title' => $card->getTitle(),
			'description' => (string)$card->getDescription(),
			'type' => $card->getType(),
			'owner' => (string)$card->getOwner(),
			'order' => $card->getOrder(),
			'archived' => (bool)$card->getArchived(),
			'done' => $this->dateToTimestamp($card->getDone()),
			'duedate' => $this->dateToString($card->getDuedate()),
			'lastModified' => $card->getLastModified(),
			'attachmentCount' => $card->getAttachmentCount(),
		];
	}

	/**
	 * Tells whether a card may appear in a listing.
	 *
	 * `CardMapper::findAll()` already filters `archived = false` and `deleted_at = 0` in SQL
	 * (`lib/Db/CardMapper.php:141-142` on Deck v1.17.5); the Deck services additionally refuse a
	 * deleted card in `PermissionService::checkPermission()`. Re-checking here is what makes the
	 * guarantee provable in a unit test, since the mapper is mocked.
	 *
	 * @param Card $card Card returned by the mapper or by a Deck service.
	 * @return bool True when the card is neither deleted nor archived.
	 */
	public function isListed(Card $card): bool {
		return $card->getDeletedAt() === 0 && !$card->getArchived();
	}

	/**
	 * @param \DateTime|null $date Value read from a Deck `datetime` column.
	 * @return int|null Unix timestamp, or null when there is no date.
	 */
	private function dateToTimestamp(?\DateTime $date): ?int {
		return $date?->getTimestamp();
	}

	/**
	 * @param mixed $date Deck stores `duedate` as `datetime`, but a stub or a fresh entity may still hold the raw string.
	 * @return string|null Date formatted as `YYYY-MM-DD`, or null.
	 */
	private function dateToString(mixed $date): ?string {
		if ($date instanceof \DateTime) {
			return $date->format(self::DATE_FORMAT);
		}

		return is_string($date) && $date !== '' ? $date : null;
	}
}
