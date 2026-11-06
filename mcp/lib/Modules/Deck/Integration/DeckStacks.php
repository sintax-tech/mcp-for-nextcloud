<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\Mcp\Modules\Deck\Integration\DeckErrors;

/** Lists and creates stacks, counts the cards of a stack the caller may manage, and reads a stack back from the trash. */
final class DeckStacks {
	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param CardReferenceFilter $references Fresh READ boundary applied to cards embedded on a stack.
	 * @param DeckCardCounts $counts Card total taken after the manage check, without a second permission check.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private CardReferenceFilter $references,
		private DeckCardCounts $counts
	) {
	}

	/**
	 * Stacks of one board, with their cards embedded by the Deck service.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $boardId Board to list.
	 * @return list<Stack> Stacks of the board.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::listStacks()
	 */
	public function listStacks(string $userId, int $boardId): array {
		$this->services->bindUser($userId);

		/** @var StackService $stackService */
		$stackService = $this->services->get(StackService::class);

		// `StackService::findAll()` checks PERMISSION_READ on the board before listing its stacks.
		$stacks = $stackService->findAll($boardId);
		$referenceView = []; // one public call only; never retained on the gateway
		foreach ($stacks as $key => $stack) {
			if (($stack->getCards() ?? []) !== []) {
				$safeStack = clone $stack;
				$safeStack->setCards(array_map(
					function (Card $card) use ($userId, &$referenceView): Card {
						return $this->references->filter($userId, $card, $referenceView);
					},
					$stack->getCards(),
				));
				$stacks[$key] = $safeStack;
			}
		}
		return $stacks;
	}

	/**
	 * Creates a list on a board the caller manages.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param int $boardId Board that receives the list.
	 * @param string $title List title.
	 * @param int|null $order Position among the lists, or null to go after the last one.
	 * @return Stack Created list.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::createStack()
	 */
	public function createStack(string $userId, int $boardId, string $title, ?int $order): Stack {
		$this->services->bindUser($userId);

		/** @var StackService $stackService */
		$stackService = $this->services->get(StackService::class);

		if ($order === null) {
			/** @var PermissionService $permissionService */
			$permissionService = $this->services->get(PermissionService::class);
			/** @var StackMapper $stackMapper */
			$stackMapper = $this->services->get(StackMapper::class);
			// `StackService::create()` needs PERMISSION_MANAGE; reading the lists is an access of its own,
			// so the same check comes before the query.
			$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
			$highest = -1;
			foreach ($stackMapper->findAll($boardId) as $stack) {
				$highest = max($highest, (int)$stack->getOrder());
			}
			$order = $highest + 1;
		}

		// Deck v1.17.5 `create(string $title, int $boardId, int $order)`; it checks MANAGE and refuses an archived board.
		return $stackService->create($title, $boardId, $order);
	}

	/**
	 * Cards a list holds, active and archived (the Deck trash is not counted).
	 *
	 * @param string $userId UID of the authenticated caller, who must manage the board of the list.
	 * @param int $stackId List to count.
	 * @return int Number of cards.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::stackCardCount()
	 */
	public function stackCardCount(string $userId, int $stackId): int {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);

		// The same check `StackService::delete()` runs, taken before any card is read.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_MANAGE);

		return $this->counts->cardsInStack($stackId);
	}

	/**
	 * The active lists of a board, read again from the database (deleted ones left out, no cards embedded).
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board.
	 * @param int $boardId Board to read.
	 * @return list<Stack> Its lists.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 * @see DeckGatewayInterface::stacksOf()
	 */
	public function stacksOf(string $userId, int $boardId): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);

		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);

		return array_values($stackMapper->findAll($boardId));
	}

	/**
	 * Whether a list went to the Deck trash, read again from the database.
	 *
	 * `StackMapper::find()` keeps the entity of the request in memory, with the `deleted_at` the failed delete set on
	 * it even when the update never reached the database, so the lists are queried again instead.
	 *
	 * @param string $userId UID of the authenticated caller, who must be able to read the board of the list.
	 * @param int $stackId List to look for.
	 * @return Stack|null The list as found in the trash, or null when it is still among the active lists.
	 * @throws \Throwable When it is found nowhere, or any Deck failure; the caller treats it as "cannot tell".
	 * @see DeckGatewayInterface::deletedStack()
	 */
	public function deletedStack(string $userId, int $stackId): ?Stack {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);

		$boardId = $stackMapper->findBoardId($stackId) ?? throw new NoPermissionException('Permission denied');
		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ);

		foreach ($stackMapper->findDeleted($boardId) as $stack) {
			if ((int)$stack->getId() === $stackId) {
				return $stack;
			}
		}
		foreach ($stackMapper->findAll($boardId) as $stack) {
			if ((int)$stack->getId() === $stackId) {
				return null;
			}
		}

		throw new \RuntimeException('The list is neither active nor in the trash');
	}
}
