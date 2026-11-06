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
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Integration\DeckErrors;

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
	 * Boards shared with the user, archived ones excluded.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return list<Board> Boards visible to the caller, unsorted.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::listBoards()
	 */
	public function listBoards(string $userId): array {
		/** @var BoardService $boardService */
		$boardService = $this->services->bindUser($userId);

		// `since: -1` disables the change-tracking filter; `fullDetails: false` skips the settings and session enrichment.
		return $boardService->findAll(-1, false, false);
	}

	/**
	 * Creates a board owned by the caller, with the default labels Deck gives every new board.
	 *
	 * @param string $userId UID of the authenticated caller, the owner of the new board.
	 * @param string $title Board title, 1 to 100 characters.
	 * @param string $color Six hexadecimal digits, without `#`.
	 * @return Board Created board.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::createBoard()
	 */
	public function createBoard(string $userId, string $title, string $color): Board {
		$boardService = $this->services->bindUser($userId);

		// Deck v1.17.5 `create(string $title, string $userId, string $color)`; it adds the four default labels.
		return $boardService->create($title, $userId, $color);
	}

	/**
	 * Cards a board holds in all its lists, active and archived (the Deck trash is not counted).
	 *
	 * @param string $userId UID of the authenticated caller, who must manage the board.
	 * @param int $boardId Board to count.
	 * @return int Number of cards.
	 * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
	 * @see DeckGatewayInterface::boardCardCount()
	 */
	public function boardCardCount(string $userId, int $boardId): int {
		$this->services->bindUser($userId);

		/** @var PermissionService $permissionService */
		$permissionService = $this->services->get(PermissionService::class);

		$permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);

		return $this->counts->cardsInBoard($boardId);
	}

	/**
	 * The boards the caller owns, read again from the database, deleted ones included.
	 *
	 * `BoardMapper::findAllByOwner()` is a fresh query; `find()` would answer from the entity cache of the request.
	 * Only the caller's own boards are read, so no ACL check is needed.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return list<Board> Their boards.
	 * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
	 * @see DeckGatewayInterface::ownedBoards()
	 */
	public function ownedBoards(string $userId): array {
		$this->services->bindUser($userId);

		/** @var BoardMapper $boardMapper */
		$boardMapper = $this->services->get(BoardMapper::class);

		return array_values($boardMapper->findAllByOwner($userId) ?: []);
	}
}
