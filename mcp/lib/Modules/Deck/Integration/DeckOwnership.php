<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;

/** Reads who owns a board, a list or a card after the caller's own permission check. */
final class DeckOwnership {
	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 */
	public function __construct(
		private DeckServiceContext $services
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function stackOwnership(string $userId, int $stackId): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);

		// Check first: Deck answers a missing or unreadable stack with the same exception it uses
		// for a denied one, so no owner or title is read before the caller may write there.
		$permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_EDIT);

		return $this->ownershipOfBoard($stackMapper->findBoardId($stackId));
	}

	/**
	 * {@inheritDoc}
	 */
	public function cardOwnership(string $userId, int $cardId): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		// The same check `CardService::update()` and `CardService::delete()` run, taken before the
		// read so a caller without edit access never learns who owns the board.
		$permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_EDIT);

		return $this->ownershipOfBoard($cardMapper->findBoardId($cardId));
	}

	/**
	 * {@inheritDoc}
	 */
	public function boardOwnership(string $userId, int $boardId): array {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);

		// Check first: the owner and the title are read only for somebody who may manage the board.
		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		return $this->ownershipOfBoard($boardId);
	}

	/**
	 * Owner, display name and title of a board, read after its permission check succeeded.
	 *
	 * @param mixed $boardId Board id resolved by a Deck mapper, or null when the entity is gone.
	 * @return array{owner: string, ownerDisplayName: string, name: string} Ownership of the board.
	 * @throws NoPermissionException When the board cannot be resolved, as the Deck answers it.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 */
	private function ownershipOfBoard(mixed $boardId): array {
		if ($boardId === null) {
			// Reached only if Deck and this lookup disagree; answer like Deck does, without leaking.
			throw new NoPermissionException('Permission denied');
		}

		/** @var BoardService $boardService */
		$boardService = $this->services->get(BoardService::class);
		// `fullDetails: false` skips the ACL and session enrichment; `allowDeleted: true` keeps an
		// archived board answerable here, because the write itself is what refuses it.
		$board = $boardService->find((int)$boardId, false, true);
		$owner = (string)$board->getOwner();

		return [
			'owner' => $owner,
			'ownerDisplayName' => $this->services->displayName($owner),
			'name' => (string)$board->getTitle(),
		];
	}
}
