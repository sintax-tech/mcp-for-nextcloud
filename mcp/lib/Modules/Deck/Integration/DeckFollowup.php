<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCP\AppFramework\Utility\ITimeFactory;

/** Scans boards the caller may manage for cards that match a follow-up filter. */
final class DeckFollowup {
	/** Visible boards a follow-up scans at most, so one call stays a bounded number of queries. */
	private const MAX_BOARDS = 100;

	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param CardReferenceFilter $references Fresh READ boundary applied to a card the scan keeps.
	 * @param UserTimezone|null $zones Timezone of the caller, or null when PHP's default decides the day.
	 * @param ITimeFactory $time Clock the follow-up reads overdue from.
	 * @param DeckBoards $boards Board list the scan filters down to boards the caller may manage.
	 * @param DeckCardQuery $query Assignment loader shared with the card listing.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private CardReferenceFilter $references,
		private ?UserTimezone $zones,
		private ITimeFactory $time,
		private DeckBoards $boards,
		private DeckCardQuery $query
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function followupCards(string $userId, string $status, ?int $boardId, ?string $assignee, ?string $dueBefore, int $limit): array {
		// `listBoards()` binds the caller to the Deck before anything is read for them.
		$managed = $this->managedBoards($userId, $boardId);
		$now = $this->time->getTime();
		$zone = $this->zone($userId);

		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		$truncated = $managed['capped'];
		$items = [];
		$referenceView = [];

		foreach ($managed['boards'] as $board) {
			// One card past the limit is proof the answer is incomplete, so the scan stops there.
			if (count($items) > $limit) {
				break;
			}

			$stackIds = array_map(
				static fn (Stack $stack): int => (int)$stack->getId(),
				$stackMapper->findAll((int)$board->getId()),
			);
			if ($stackIds === []) {
				continue;
			}

			// Deck already leaves archived and deleted cards out.
			$matching = [];
			foreach ($this->cardsOfStacks($cardMapper, $stackIds) as $cardsOfStack) {
				foreach ($cardsOfStack ?? [] as $card) {
					if (\OCA\Mcp\Tools\Deck\CardCriteria::matches($card, $status, $dueBefore, $now, $zone)) {
						$matching[] = $card;
					}
				}
			}
			if ($matching === []) {
				continue;
			}

			$this->query->attachAssignments($matching);

			foreach ($matching as $card) {
				if ($assignee !== null && !in_array($assignee, \OCA\Mcp\Tools\Deck\CardCriteria::assignedUids($card), true)) {
					continue;
				}

				$items[] = ['card' => $this->references->filter($userId, $card, $referenceView), 'boardId' => (int)$board->getId()];
			}
		}

		if (count($items) > $limit) {
			$truncated = true;
			$items = array_slice($items, 0, $limit);
		}

		return ['items' => $items, 'truncated' => $truncated];
	}

	/**
	 * Boards a follow-up scans: the ones the caller may manage, never the ones merely shared with
	 * them, capped at {@see self::MAX_BOARDS} so the walk stays a bounded number of queries.
	 *
	 * `PermissionService::getPermissions()` already answers the owner as allowed
	 * (`lib/Service/PermissionService.php:80` on Deck v1.17.5), so an explicit ACL lookup and
	 * ownership are the same check here.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int|null $boardId Restrict the scan to this board, or null for all of them.
	 * @return array{boards: list<Board>, capped: bool} Boards to scan, and whether visible boards
	 *     were left unexamined by the ceiling.
	 */
	private function managedBoards(string $userId, ?int $boardId): array {
		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);

		$boards = $this->boards->listBoards($userId);
		if ($boardId !== null) {
			$boards = array_values(array_filter(
				$boards,
				static fn (Board $board): bool => (int)$board->getId() === $boardId,
			));
		}

		$capped = count($boards) > self::MAX_BOARDS;
		$managed = [];
		foreach (array_slice($boards, 0, self::MAX_BOARDS) as $board) {
			$permissions = $permissionService->getPermissions((int)$board->getId(), $userId);
			if (($permissions[Acl::PERMISSION_MANAGE] ?? false) === true) {
				$managed[] = $board;
			}
		}

		return ['boards' => $managed, 'capped' => $capped];
	}

	/**
	 * Open cards of a board's stacks, grouped by stack in the order given.
	 *
	 * Deck 1.17 (Nextcloud 33) reads them in one query with `CardMapper::findAllForStacks()`; Deck 1.15 and 1.16
	 * (Nextcloud 31 and 32) do not have it, so their cards are read stack by stack with `findAll()`, the query the
	 * grouped one repeats: same filters (not archived, not deleted) and the same order (`order`, then `id`).
	 *
	 * @param object $cardMapper Deck's CardMapper, of whichever supported Deck is installed.
	 * @param list<int> $stackIds Stacks of one board.
	 * @return array<int, Card[]|null> Cards per stack id; a stack without cards may be null.
	 */
	private function cardsOfStacks(object $cardMapper, array $stackIds): array {
		if (method_exists($cardMapper, 'findAllForStacks')) {
			return $cardMapper->findAllForStacks($stackIds) ?: [];
		}

		$cards = [];
		foreach ($stackIds as $stackId) {
			$found = $cardMapper->findAll($stackId);
			$cards[$stackId] = is_array($found) ? array_values($found) : [];
		}

		return $cards;
	}

	/**
	 * Timezone the caller reads due dates in.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return \DateTimeZone Their preference, or PHP's default when no resolver was given.
	 */
	private function zone(string $userId): \DateTimeZone {
		return $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
	}
}
