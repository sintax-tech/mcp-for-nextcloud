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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Converts Deck entities into the plain arrays returned by the Deck tools.
 *
 * Native attachmentCount is omitted: it includes files outside the caller's Files scope/tags.
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
	 * @param Card $card Card whose READ and parent visibility were checked by the gateway.
	 * @param int $boardId Its readable board.
	 * @return array{id: int, boardId: int, title: string, url: string} Minimal reference without native counters or relations.
	 */
	public function reference(Card $card, int $boardId): array {
		return ['id' => (int)$card->getId(), 'boardId' => $boardId, 'title' => (string)$card->getTitle(),
			'url' => $this->urls->linkToRouteAbsolute(self::CARD_ROUTE, ['boardId' => $boardId, 'cardId' => $card->getId()])];
	}

	/**
	 * Serialize the card whitelist with account-local date and instant fields.
	 *
	 * @param Card $card Card as returned by the Deck services.
	 * @param int|null $boardId Board the card belongs to, resolved once by the gateway for listings.
	 * @return array{id: int, stackId: int, boardId: int|null, title: string, description: string, type: string, owner: string, order: int, archived: bool, done: int|null, duedate: string|null, dueAt: string|null, lastModified: int, assignedUsers: list<array{uid: string, displayName: string}>, overdue: bool, url: string|null}
	 */
	public function card(Card $card, ?int $boardId = null): array {
		$boardId ??= $card->getRelatedBoard()?->getId();

		$assignedUsers = [];
		foreach (\OCA\Mcp\Modules\Deck\Integration\CardCriteria::assignedUids($card) as $uid) {
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
			'duedate' => \OCA\Mcp\Modules\Deck\Integration\CardCriteria::dueDay($card, $this->zone),
			'dueAt' => \OCA\Mcp\Modules\Deck\DueDates\CardDueDateView::dueAt($card->getDuedate(), $this->zone),
			'lastModified' => $card->getLastModified(),
			'assignedUsers' => $assignedUsers,
			'overdue' => \OCA\Mcp\Modules\Deck\Integration\CardCriteria::isOverdue($card, $this->time->getTime(), $this->zone),
			'url' => $boardId === null
				? null
				: $this->urls->linkToRouteAbsolute(self::CARD_ROUTE, ['boardId' => $boardId, 'cardId' => $card->getId()]),
		];
	}

	/**
	 * Share the account timezone with the bound handler's input validation and read-back comparison.
	 *
	 * @return \DateTimeZone Account timezone already used to serialize this handler's cards.
	 */
	public function timezone(): \DateTimeZone {
		return $this->zone;
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

	/**
	 * The assignment of one account on a card, read again from Deck.
	 *
	 * @param list<\OCA\Deck\Db\Assignment> $assignments Assignments read again from Deck.
	 * @param string $uid Account assigned.
	 * @return \OCA\Deck\Db\Assignment|null The user assignment of that account, or null when it has none.
	 */
	public static function assignmentOf(array $assignments, string $uid): ?\OCA\Deck\Db\Assignment {
		foreach ($assignments as $assignment) {
			// Type 0 is `Assignment::TYPE_USER` (Deck v1.17.5), the only kind these tools assign.
			if ((string)$assignment->getParticipant() === $uid && (int)$assignment->getType() === 0) {
				return $assignment;
			}
		}

		return null;
	}

	/**
	 * The list a creation added, among the lists read again after it threw.
	 *
	 * @param list<Stack> $stacks Active lists of the board now.
	 * @param string $title Title of the list asked for.
	 * @param list<int> $known Ids that existed before, or were created earlier in the same call.
	 * @return Stack|null The newest list with that title that is not one of `$known`, or null.
	 */
	public static function newStack(array $stacks, string $title, array $known): ?Stack {
		$found = null;
		foreach ($stacks as $stack) {
			if (!in_array((int)$stack->getId(), $known, true) && (string)$stack->getTitle() === $title
				&& ($found === null || (int)$stack->getId() > (int)$found->getId())) {
				$found = $stack;
			}
		}

		return $found;
	}

	/**
	 * @param array<\OCA\Deck\Db\Assignment> $assignments assignments after the successful native removal
	 * @param string $uid account removed from this card
	 * @return list<\OCA\Deck\Db\Assignment> unchanged remaining assignments
	 */
	public static function withoutAssignee(array $assignments, string $uid): array {
		return array_values(array_filter($assignments,
			static fn ($assignment): bool => !($assignment->getType() === \OCA\Deck\Db\Acl::PERMISSION_TYPE_USER && (string)$assignment->getParticipant() === $uid),
		));
	}
}
