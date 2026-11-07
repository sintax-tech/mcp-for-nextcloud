<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;

/** Counts cards of one list or of every list of a board, with no ACL check and no session bind. */
final class DeckCardCounts {
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
	 * Cards of one list that still count as cards: the active ones and the archived ones.
	 *
	 * Deck leaves the deleted cards (its trash) out of both queries, and a card in the trash is not a reason
	 * to keep a list.
	 *
	 * @param int $stackId List to count; the caller was already checked.
	 * @return int Number of cards.
	 */
	public function cardsInStack(int $stackId): int {
		/** @var CardMapper $cardMapper */
		$cardMapper = $this->services->get(CardMapper::class);

		return count($cardMapper->findAll($stackId) ?: []) + count($cardMapper->findAllArchived($stackId) ?: []);
	}

	/**
	 * Cards of every list of a board, counted as {@see self::cardsInStack()} does.
	 *
	 * @param int $boardId Board to count; the caller was already checked.
	 * @return int Number of cards.
	 */
	public function cardsInBoard(int $boardId): int {
		/** @var StackMapper $stackMapper */
		$stackMapper = $this->services->get(StackMapper::class);

		$cards = 0;
		foreach ($stackMapper->findAll($boardId) as $stack) {
			$cards += $this->cardsInStack((int)$stack->getId());
		}

		return $cards;
	}
}
