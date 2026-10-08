<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_delete_stack`: deletes a list, but only while it holds no card.
 *
 * Deck deletes a list whatever it holds, so the gateway counts the cards again at the moment of the delete
 * and refuses with the number of cards left. The delete is Deck's soft delete: the list goes to the trash.
 */
final class DeleteStackHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_delete_stack';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload is the deleted list.
	 *
	 * @param array<string, mixed> $arguments Requires `stackId`; accepts `confirm_shared`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$confirmation = $this->confirmShared($arguments, $userId, $this->gateway->stackOwnership($userId, (int)$arguments['stackId']));
			if ($confirmation !== null) {
				return $confirmation;
			}

			$stackId = (int)$arguments['stackId'];
			$warnings = [];
			// The refusals of the gateway (cards in the list, a card that arrived during the delete) are decided by
			// it; any other exception is followed by a look in the trash of the board.
			$stack = $this->afterWrite(
				fn () => $this->gateway->deleteEmptyStack($userId, $stackId),
				fn () => $this->gateway->deletedStack($userId, $stackId),
				$warnings,
			);

			return [
				'id' => (int)$stack->getId(),
				'boardId' => (int)$stack->getBoardId(),
				'title' => (string)$stack->getTitle(),
				'deleted' => true,
			] + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A deleted list leaves the lists of its board.
	 */
	protected function readWith(): string {
		return 'deck_list_stacks';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
