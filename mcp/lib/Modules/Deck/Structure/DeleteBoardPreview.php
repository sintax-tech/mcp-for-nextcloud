<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Structure\DeleteBoardHandler;

/** Prepares the same DeleteBoard confirmation without a write. */
final class DeleteBoardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(DeleteBoardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * A board can only be deleted by its owner and empty, so a board of somebody else or one that holds a card has
	 * no plan: the refusal is the answer, as the write would give it.
	 *
	 * @param array{boardId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 * @throws DeckRefusalException When the caller is not the owner or any list holds a card.
	 */
	private function prepare(array $arguments, string $userId): array {
		$boardId = (int)$arguments['boardId'];
		$ownership = $this->context->gateway()->boardOwnership($userId, $boardId);
		if ($ownership['owner'] !== $userId) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardNotOwned();
		}
		$cards = $this->context->gateway()->boardCardCount($userId, $boardId);
		if ($cards > 0) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::boardNotEmpty($cards);
		}

		return [
			'action' => DeleteBoardHandler::TOOL,
			'board' => ['id' => $boardId, 'title' => $ownership['name'], 'cards' => $cards],
			'stacks' => (new \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection($this->context->gateway()))->stackTitles($userId, $boardId),
			'shared' => [],
			'recoverable' => true,
			'consequence' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDeleteBoardConsequence(),
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDeleteBoard(),
		];
	}
}
