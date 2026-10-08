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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Converts Deck entities into the plain arrays returned by the Deck tools.
 *
 * Only fields the contract lists are exposed; Deck internals such as `shareToken`, ACL rows or
 * physical storage paths are never copied into the response. The card carries what a follow-up
 * needs to be actionable: who is responsible, whether it is late, and the absolute link to the
 * card in the Deck web interface.
 */
final class CardFormatter {
	/** Route of the Deck card page, Deck `appinfo/routes.php` `page#indexCard`. */
	private const CARD_ROUTE = 'deck.page.indexCard';

	/** @var array<string, string> Display names already resolved in this request. */
	private array $names = [];

	/**
	 * @param IURLGenerator $urls Builds the absolute link to the card in the Deck web interface.
	 * @param ITimeFactory $time Clock the `overdue` flag is read from, so it can be pinned in a test.
	 * @param IUserManager|null $users Resolves an assigned UID to its display name; without it the
	 *     UID is answered as is.
	 * @param \DateTimeZone|null $zone Timezone of the caller, deciding which day `duedate` and
	 *     `overdue` speak of; PHP's default when absent.
	 */
	public function __construct(
		private IURLGenerator $urls,
		private ITimeFactory $time,
		private ?IUserManager $users = null,
		private ?\DateTimeZone $zone = null,
	) {
		$this->zone ??= new \DateTimeZone(date_default_timezone_get());
	}

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
	 * @return array{id: int, stackId: int, boardId: int|null, title: string, description: string, type: string, owner: string, order: int, archived: bool, done: int|null, duedate: string|null, lastModified: int, attachmentCount: int|null, assignedUsers: list<array{uid: string, displayName: string}>, overdue: bool, url: string|null}
	 */
	public function card(Card $card, ?int $boardId = null): array {
		$boardId ??= $card->getRelatedBoard()?->getId();

		$assignedUsers = [];
		foreach (CardCriteria::assignedUids($card) as $uid) {
			$assignedUsers[] = [
				'uid' => $uid,
				'displayName' => $this->displayName($uid),
			];
		}

		return [
			'id' => $card->getId(),
			'stackId' => $card->getStackId(),
			'boardId' => $boardId,
			'title' => $card->getTitle(),
			'description' => (string)$card->getDescription(),
			'type' => $card->getType(),
			'owner' => (string)$card->getOwner(),
			'order' => $card->getOrder(),
			'archived' => (bool)$card->getArchived(),
			'done' => $this->dateToTimestamp($card->getDone()),
			'duedate' => CardCriteria::dueDay($card, $this->zone),
			'lastModified' => $card->getLastModified(),
			'attachmentCount' => $card->getAttachmentCount(),
			'assignedUsers' => $assignedUsers,
			'overdue' => CardCriteria::isOverdue($card, $this->time->getTime(), $this->zone),
			'url' => $boardId === null
				? null
				: $this->urls->linkToRouteAbsolute(self::CARD_ROUTE, ['boardId' => $boardId, 'cardId' => $card->getId()]),
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
	 * Display name of an assigned user, resolved once per request.
	 *
	 * @param string $userId UID whose name the caller sees.
	 * @return string Name of the account, or the UID when it is gone or has none.
	 */
	private function displayName(string $userId): string {
		if (!isset($this->names[$userId])) {
			$this->names[$userId] = $this->users?->get($userId)?->getDisplayName() ?: $userId;
		}

		return $this->names[$userId];
	}

	/**
	 * @param \DateTime|null $date Value read from a Deck `datetime` column.
	 * @return int|null Unix timestamp, or null when there is no date.
	 */
	private function dateToTimestamp(?\DateTime $date): ?int {
		return $date?->getTimestamp();
	}
}
