<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCP\AppFramework\Utility\ITimeFactory;

/** Reads cards, the board of a card, its assignments and state, and the card a failed creation saved. */
final class DeckCardQuery {
	/** How far back a card counts as the one a failed creation saved, in seconds. */
	private const CREATED_WITHIN = 120;

	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param CardReferenceFilter $references Fresh READ boundary applied to cards returned to a caller.
	 * @param ITimeFactory $time Clock the failed-creation lookup reads.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private CardReferenceFilter $references,
		private ITimeFactory $time
	) {
	}

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
	 * @see DeckGatewayInterface::listCards()
	 */
	public function listCards(string $userId, int $stackId, int $limit, int $offset): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		// CardMapper::findAll() is a plain query with no ACL at all, so the check comes first.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);

		$items = $cardMapper->findAll($stackId, $limit, $offset);
		$items = is_array($items) ? array_values($items) : [];

		// Deck embeds the assignments of a card only on the detail read, so the listing loads them
		// in one query for the whole page instead of one query per card.
		$this->attachAssignments($items);
		$referenceView = [];

		return [
			'items' => array_map(function (Card $card) use ($userId, &$referenceView): Card {
				return $this->references->filter($userId, $card, $referenceView);
			}, $items),
			'boardId' => $stackMapper->findBoardId($stackId),
		];
	}

	/**
	 * One card with references filtered for the caller; native file counts are not public.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $cardId Card to read.
	 * @return Card Enriched card.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::findCard()
	 */
	public function findCard(string $userId, int $cardId): Card {
		$this->services->bindUser($userId);

		/** @var CardService $cardService */
		$cardService = $this->services->get(CardService::class);

		// `CardService::find()` checks PERMISSION_READ and refuses a deleted card.
		return $this->references->filter($userId, $cardService->find($cardId));
	}

	/**
	 * One card read again from the database, deleted or not, so a write tool can tell what a failed write left.
	 *
	 * `CardMapper::find()` runs a fresh query and, unlike `CardService::find()`, answers a deleted card too.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board of the card.
	 * @param int $cardId Card to read.
	 * @return Card The card as stored, without the enrichment of the Deck service.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 * @see DeckGatewayInterface::cardState()
	 */
	public function cardState(string $userId, int $cardId): Card {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		// Deck v1.17.5 `checkPermission($mapper, $id, $permission, $userId, $allowDeletedCard)`: a deleted card is
		// exactly what a delete leaves, so it stays readable here.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ, null, true);

		return $this->references->filter($userId, $cardMapper->find($cardId, false));
	}

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
	 * @see DeckGatewayInterface::findCreatedCard()
	 */
	public function findCreatedCard(string $userId, int $stackId, string $title, array $excludeIds = []): ?Card {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);

		$since = $this->time->getTime() - self::CREATED_WITHIN;
		$found = null;
		foreach ($cardMapper->findAll($stackId) ?: [] as $card) {
			if (!$card instanceof Card || in_array((int)$card->getId(), $excludeIds, true)) {
				continue;
			}
			if ((string)$card->getOwner() === $userId && (string)$card->getTitle() === $title && (int)$card->getCreatedAt() >= $since
				&& ($found === null || (int)$card->getId() > (int)$found->getId())) {
				$found = $card;
			}
		}

		return $found === null ? null : $this->references->filter($userId, $found);
	}

	/**
	 * The assignments of one card, read again from the database.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the card.
	 * @param int $cardId Card to read.
	 * @return list<\OCA\Deck\Db\Assignment> Its assignments.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 * @see DeckGatewayInterface::cardAssignments()
	 */
	public function cardAssignments(string $userId, int $cardId): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var AssignmentMapper $assignmentMapper */
		$assignmentMapper = $this->services->get(AssignmentMapper::class);

		$permissionService->checkPermission($this->services->get(CardMapper::class), $cardId, Acl::PERMISSION_READ);

		return array_values($assignmentMapper->findIn([$cardId]));
	}

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
	 * @see DeckGatewayInterface::cardBoardId()
	 */
	public function cardBoardId(string $userId, int $cardId): int {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		// `CardMapper::findBoardId()` is a plain query, so the read check the card itself needs comes first.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ);

		return (int)$cardMapper->findBoardId($cardId);
	}

	/**
	 * Embeds the assignments of a batch of cards, in one query for all of them.
	 *
	 * @param list<Card> $cards Cards of one stack, board or page, mutated in place.
	 */
	public function attachAssignments(array $cards): void {
		if ($cards === []) {
			return;
		}

		/** @var AssignmentMapper $assignmentMapper */
		$assignmentMapper = $this->services->get(AssignmentMapper::class);

		$byCardId = [];
		foreach ($assignmentMapper->findIn(array_map(static fn (Card $card): int => $card->getId(), $cards)) as $assignment) {
			$byCardId[(int)$assignment->getCardId()][] = $assignment;
		}

		foreach ($cards as $card) {
			$card->setAssignedUsers($byCardId[$card->getId()] ?? []);
		}
	}
}
