<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Moving;


use OCA\Mcp\Modules\Deck\Moving\MoveCardHandler;
/** Prepares the same MoveCard confirmation without a write. */
final class MoveCardPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context lazy per-caller collaborators */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(MoveCardHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array{cardId: int, stackId: int, order?: int} $arguments
	 * @param string $userId UID of the authenticated caller.
	 * @return array<string, mixed>
	 */
	private function prepare(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$projection = new \OCA\Mcp\Modules\Deck\Integration\DeckPlanProjection($this->context->gateway());
		$card = $projection->readCard($userId, $cardId);
		$origin = $this->context->place($userId, (int)$card['stackId']);
		$destination = $this->context->place($userId, (int)$arguments['stackId']);

		return [
			'action' => MoveCardHandler::TOOL,
			'card' => ['id' => $cardId] + $projection->fields(),
			'origin' => $origin,
			'destination' => $destination,
			'order' => array_key_exists('order', $arguments) ? (int)$arguments['order'] : null,
			'sameList' => $origin['stackId'] === $destination['stackId'],
			'shared' => array_values(array_filter([$origin, $destination], static fn (array $place): bool => $place['shared'])),
			'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planMove(),
		];
	}
}
