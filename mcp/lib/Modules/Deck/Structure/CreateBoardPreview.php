<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Structure\CreateBoardHandler;

/** Prepares the same CreateBoard confirmation without a write. */
final class CreateBoardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(CreateBoardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * The tree of a board as it will be built: the board, its lists and their cards, validated by the same
	 * {@see BoardBlueprint} the execution reads, so anything the write would refuse is refused here.
	 *
	 * @param array<string, mixed> $arguments Arguments of `deck_create_board`.
	 * @param string $userId UID of the authenticated caller, the owner of the board.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException Naming the field when the structure is refused.
	 */
	private function prepare(array $arguments, string $userId): array {
		$blueprint = \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::parse($arguments, $userId, $this->context->formatter($userId)->timezone());
		// Only the owner can be assigned on a new board, and BoardBlueprint has already refused anybody else.
		$self = ['uid' => $userId, 'displayName' => $this->context->displayName($userId)];

		return [
			'action' => CreateBoardHandler::TOOL,
			'board' => ['title' => $blueprint['title'], 'color' => $blueprint['color'], 'colorGiven' => $blueprint['colorGiven']],
			'owner' => $userId,
			'stacks' => array_map(static fn (array $stack): array => [
				'title' => $stack['title'],
				'cards' => array_map(static fn (array $card): array => [
					'title' => $card['title'],
					'description' => $card['description'],
					'duedate' => $card['duedate'],
					'assignees' => array_map(static fn (string $uid): array => $self, $card['assignees']),
				], $stack['cards']),
			], $blueprint['stacks']),
			'totals' => ['stacks' => count($blueprint['stacks']), 'cards' => $blueprint['cardCount']],
			'limits' => ['stacks' => \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_STACKS, 'cards' => \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_CARDS],
			'shared' => [],
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planCreateBoard(),
		];
	}
}
