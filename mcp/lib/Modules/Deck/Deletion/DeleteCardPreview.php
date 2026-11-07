<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Deletion;


use OCA\Mcp\Modules\Deck\Deletion\DeleteCardHandler;
/** Prepares the same DeleteCard confirmation without a write. */
final class DeleteCardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(DeleteCardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array{cardId: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function prepare(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$projection = new \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection($this->context->gateway());
		$card = $projection->readCard($userId, $cardId);
		$board = $this->context->gateway()->cardOwnership($userId, $cardId);

		return [
			'action' => DeleteCardHandler::TOOL,
			'card' => ['id' => $cardId] + $projection->fields(),
			'board' => $this->context->board($board, $userId),
			'shared' => $board['owner'] === $userId ? [] : [$this->context->board($board, $userId)],
			// Deck soft-deletes, but no tool of this app brings a card back, so the plan says so instead of
			// leaving the user to discover it.
			'recoverable' => false,
			'consequence' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDeleteConsequence(),
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planDelete(),
		];
	}
}
