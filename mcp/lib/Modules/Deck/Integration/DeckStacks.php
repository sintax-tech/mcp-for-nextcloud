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
	 * {@inheritDoc}
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
	 * {@inheritDoc}
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
	 * {@inheritDoc}
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
	 * {@inheritDoc}
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
	 * {@inheritDoc}
	 *
	 * `StackMapper::find()` keeps the entity of the request in memory, with the `deleted_at` the failed delete set on
	 * it even when the update never reached the database, so the lists are queried again instead.
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
