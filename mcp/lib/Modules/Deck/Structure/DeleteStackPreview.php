<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Structure\DeleteStackHandler;

/** Prepares the same DeleteStack confirmation without a write. */
final class DeleteStackPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(DeleteStackHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * A list can only be deleted empty, so the cards are counted for the plan and a list that holds any has no
	 * plan at all: the refusal is the answer, as the write would give it.
	 *
	 * @param array{stackId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 * @throws DeckRefusalException When the list holds any card.
	 */
	private function prepare(array $arguments, string $userId): array {
		$stackId = (int)$arguments['stackId'];
		$place = $this->context->place($userId, $stackId);
		$cards = $this->context->gateway()->stackCardCount($userId, $stackId);
		if ($cards > 0) {
			throw \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException::stackNotEmpty($cards);
		}
		$board = ['board' => $place['board'], 'owner' => $place['owner'], 'ownerDisplayName' => $place['ownerDisplayName'], 'shared' => $place['shared']];

		return [
			'action' => DeleteStackHandler::TOOL,
			'stack' => ['id' => $stackId, 'title' => $place['list'], 'cards' => $cards],
			'board' => $board,
			'shared' => $place['shared'] ? [$board] : [],
			'recoverable' => true,
			'consequence' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDeleteStackConsequence(),
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDeleteStack(),
		];
	}
}
