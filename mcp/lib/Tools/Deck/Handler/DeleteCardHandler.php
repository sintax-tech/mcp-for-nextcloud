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
 * Backs `deck_delete_card`.
 *
 * Deletion is irreversible from the tools: Deck soft-deletes the card the same way its web
 * interface does, and there is no tool to bring it back. The registry only reaches this handler
 * after the user confirmed the plan, and `confirm_shared` is still what a board of somebody else
 * needs before the card is deleted.
 */
final class DeleteCardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_delete_card';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts the deleted Deck card into the tool payload.
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
	 * The payload is the deleted card.
	 *
	 * @param array<string, mixed> $arguments Requires `cardId`; accepts `confirm` and `confirm_shared`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			// The user already confirmed the plan; `confirm_shared` is what the board of somebody
			// else still needs before the card is deleted.
			$confirmation = $this->confirmShared(
				$arguments,
				$userId,
				$this->gateway->cardOwnership($userId, (int)$arguments['cardId']),
			);
			if ($confirmation !== null) {
				return $confirmation;
			}

			$cardId = (int)$arguments['cardId'];
			$warnings = [];
			// Deck stamps `deleted_at` before the activity and the notifications: the card read back says whether it went.
			$card = $this->afterWrite(
				fn () => $this->gateway->deleteCard($userId, $cardId),
				function () use ($userId, $cardId) {
					$card = $this->gateway->cardState($userId, $cardId);

					return (int)$card->getDeletedAt() > 0 ? $card : null;
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
