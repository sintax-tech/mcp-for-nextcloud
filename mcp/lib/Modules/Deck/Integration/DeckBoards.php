<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Service\PermissionService;

/** Lists and creates boards, counts the cards of a board the caller may manage, and reads the boards the caller owns. */
final class DeckBoards {
	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 * @param DeckCardCounts $counts Card totals taken after the manage check, without a second permission check.
	 */
	public function __construct(
		private DeckServiceContext $services,
		private DeckCardCounts $counts
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function listBoards(string $userId): array {
		/** @var BoardService $boardService */
		$boardService = $this->services->bindUser($userId);

		// `since: -1` disables the change-tracking filter; `fullDetails: false` skips the settings and session enrichment.
		return $boardService->findAll(-1, false, false);
	}

	/**
	 * {@inheritDoc}
	 */
	public function createBoard(string $userId, string $title, string $color): Board {
		$boardService = $this->services->bindUser($userId);

		// Deck v1.17.5 `create(string $title, string $userId, string $color)`; it adds the four default labels.
		return $boardService->create($title, $userId, $color);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boardCardCount(string $userId, int $boardId): int {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);

		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		return $this->counts->cardsInBoard($boardId);
	}

	/**
	 * {@inheritDoc}
	 *
	 * `BoardMapper::findAllByOwner()` is a fresh query; `find()` would answer from the entity cache of the request.
	 * Only the caller's own boards are read, so no ACL check is needed.
	 */
	public function ownedBoards(string $userId): array {
		$this->services->bindUser($userId);

		/** @var BoardMapper $boardMapper */
		$boardMapper = $this->services->get(BoardMapper::class);

		return array_values($boardMapper->findAllByOwner($userId) ?: []);
	}
}
