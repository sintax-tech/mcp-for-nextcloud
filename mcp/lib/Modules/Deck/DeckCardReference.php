<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Access\References\ReadableDeckCard;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Platform\References\ReferenceRead;
use Throwable;

/** Projects the existing permission-checked card read without exposing native types across the module boundary. */
final class DeckCardReference implements ReadableDeckCard {
	/** @param DeckGatewayInterface $deck the same lazy gateway used by the Deck tools */
	public function __construct(private DeckGatewayInterface $deck) {
	}

	/** {@inheritDoc} */
	public function read(string $userId, int $cardId, ?int $boardId = null): ReferenceRead {
		try {
			$card = $this->deck->findCard($userId, $cardId);
			$actualBoard = $this->deck->cardBoardId($userId, $cardId);
		} catch (Throwable $e) {
			return new ReferenceRead(null, $e);
		}
		if ($boardId !== null && $boardId !== $actualBoard) {
			return new ReferenceRead();
		}
		return new ReferenceRead(['title' => (string)$card->getTitle(), 'boardId' => $actualBoard]);
	}
}
