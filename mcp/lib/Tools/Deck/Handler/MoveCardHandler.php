<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_move_card`.
 *
 * Moving between boards is the same operation: the destination stack simply belongs to another
 * board, and Deck checks edit access on the card and on the destination stack.
 */
final class MoveCardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_move_card';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts the moved Deck card into the tool payload.
	 */
	public function __construct(
		DeckGatewayInterface $gateway,
		LoggerInterface $logger,
		private CardFormatter $formatter,
	) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload is the moved card.
	 *
	 * @param array<string, mixed> $arguments Requires `cardId` and `stackId`; accepts `order`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			// Origin and destination are two boards and either can belong to somebody else; both
			// are gated before the card moves anywhere, and the origin is checked first.
			$confirmation = $this->confirmShared(
				$arguments,
				$userId,
				$this->gateway->cardOwnership($userId, (int)$arguments['cardId']),
			);
			if ($confirmation === null) {
				$confirmation = $this->confirmShared(
					$arguments,
					$userId,
					$this->gateway->stackOwnership($userId, (int)$arguments['stackId']),
				);
			}
			if ($confirmation !== null) {
				return $confirmation;
			}

			$cardId = (int)$arguments['cardId'];
			$stackId = (int)$arguments['stackId'];
			$order = array_key_exists('order', $arguments) ? (int)$arguments['order'] : null;

			// Read before the move, so a move that threw can be told from one that never happened: Deck saves the
			// new list before the activity and the reorder of the destination.
			$before = $this->gateway->cardState($userId, $cardId);
			$warnings = [];
			$card = $this->afterWrite(
				fn () => $this->gateway->moveCard($userId, $cardId, $stackId, $order),
				function () use ($userId, $cardId, $stackId, $before) {
					$card = $this->gateway->cardState($userId, $cardId);
					$moved = (int)$card->getStackId() === $stackId && (int)$card->getDeletedAt() === 0
						&& ((int)$before->getStackId() !== $stackId || (int)$card->getLastModified() !== (int)$before->getLastModified());

					return $moved ? $card : null;
				},
				$warnings,
			);

			return $this->formatter->card($card) + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
