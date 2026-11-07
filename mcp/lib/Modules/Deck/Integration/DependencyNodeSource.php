<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Card;

/** The fresh READ boundary needed to expand one node of the bounded native dependency graph. */
interface DependencyNodeSource {
	/**
	 * Reads one native outgoing adjacency after fresh source and target READ checks using the common boundary.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Source card, including archived cards still readable in Deck.
	 * @param array|null $view Parent listings shared only within one public graph/state operation.
	 * @return array{card: Card, boardId: int, targets: list<array{card: Card, boardId: int}>} Only readable targets, ordered by ID.
	 * @throws \Throwable For an unreadable source or uncertain lookup; never returns a partial accessible adjacency.
	 */
	public function dependencyNode(string $userId, int $cardId, ?array &$view = null): array;
}
