<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The plain Deck queries the real gateway resolves from the container (card, list, board and label mappers), each a
 * subclass of the Deck v1.17.5 signature stub answering from {@see NativeDeck} without any permission check, as the
 * Deck mappers do; the gateway runs the check before it calls them.
 */
final class NativeDeckMappers {
	/** Static factory only. */
	private function __construct() {
	}

	/**
	 * @param NativeDeck $deck the database behind the mappers
	 * @return array<string, object> the mappers by the class name the gateway asks the container for
	 */
	public static function of(NativeDeck $deck): array {
		return [CardMapper::class => self::cardMapper($deck), StackMapper::class => self::stackMapper($deck),
			BoardMapper::class => self::boardMapper($deck), LabelMapper::class => self::labelMapper($deck)];
	}

	/**
	 * @param NativeDeck $deck database
	 * @return CardMapper the plain card queries
	 */
	private static function cardMapper(NativeDeck $deck): CardMapper {
		return new class($deck) extends CardMapper {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function findDependenciesForCards(array $cardIds): array {
				return array_combine($cardIds, array_map(fn (int $id): array => $this->deck->edges[$id] ?? [], $cardIds));
			}

			/** {@inheritDoc} */
			public function findBoardId(int $id): ?int {
				return $this->deck->boardOfCard($id);
			}

			/** {@inheritDoc} */
			public function find($id, bool $enhance = true): Card {
				return $this->deck->card((int)$id);
			}

			/** {@inheritDoc} */
			public function findAll($stackId, ?int $limit = null, int $offset = 0, int $since = -1) {
				return $this->deck->cardsOf((int)$stackId);
			}

			/** {@inheritDoc} */
			public function findAllArchived($stackId, ?int $limit = null, int $offset = 0) {
				return $this->deck->cardsOf((int)$stackId, true);
			}

			/** {@inheritDoc} */
			public function findAllForStacks(array $stackIds, ?int $limit = null, int $offset = 0, int $since = -1): array {
				return array_combine($stackIds, array_map(fn (int $stackId): array => $this->deck->cardsOf($stackId), $stackIds));
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return StackMapper the plain list queries
	 */
	private static function stackMapper(NativeDeck $deck): StackMapper {
		return new class($deck) extends StackMapper {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function findBoardId(int $id): ?int {
				return $this->deck->boardOfStack($id);
			}

			/** {@inheritDoc} */
			public function findAll(int $boardId, ?int $limit = null, $offset = 0): array {
				return $this->deck->stacksOf($boardId);
			}

			/** {@inheritDoc} */
			public function findDeleted(int $boardId, ?int $limit = null, int $offset = 0): array {
				return $this->deck->stacksOf($boardId, true);
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return BoardMapper the boards of an owner, the ones in the trash included
	 */
	private static function boardMapper(NativeDeck $deck): BoardMapper {
		return new class($deck) extends BoardMapper {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function findAllByOwner(string $userId, ?int $limit = null, ?int $offset = null) {
				$owned = array_filter($this->deck->boards, static fn (array $board): bool => $board['owner'] === $userId);
				return array_map(fn (int $id): Board => $this->deck->board($id), array_keys($owned));
			}
		};
	}

	/**
	 * @param NativeDeck $deck database
	 * @return LabelMapper the labels of a board
	 */
	private static function labelMapper(NativeDeck $deck): LabelMapper {
		return new class($deck) extends LabelMapper {
			/** @param NativeDeck $deck database */
			public function __construct(private NativeDeck $deck) {
			}

			/** {@inheritDoc} */
			public function findAll(int $boardId, ?int $limit = null, int $offset = 0): array {
				$ids = array_keys(array_filter($this->deck->labels, static fn (array $label): bool => $label['boardId'] === $boardId));
				return array_slice(array_map(fn (int $id): Label => $this->deck->label($id), $ids), $offset, $limit);
			}
		};
	}
}
