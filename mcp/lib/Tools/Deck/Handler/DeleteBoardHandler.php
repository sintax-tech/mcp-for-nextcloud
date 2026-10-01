<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_delete_board`: deletes a board of the caller, but only while none of its lists holds a card.
 *
 * Only the owner may delete a board, so there is no `confirm_shared`: a board of somebody else is refused. The
 * gateway checks ownership and counts the cards again at the moment of the delete. The delete is Deck's soft
 * delete: the board goes to the trash with its (empty) lists.
 */
final class DeleteBoardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_delete_board';

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
	 * The payload is the deleted board.
	 *
	 * @param array<string, mixed> $arguments Requires `boardId`.
	 * @param string $userId UID of the authenticated caller, who must own the board.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$boardId = (int)$arguments['boardId'];
			$warnings = [];
			$board = $this->afterWrite(
				fn () => $this->gateway->deleteEmptyBoard($userId, $boardId),
				function () use ($userId, $boardId) {
					// Only the owner deletes a board, so the board is among the caller's own, deleted or not.
					foreach ($this->gateway->ownedBoards($userId) as $board) {
						if ((int)$board->getId() === $boardId) {
							return (int)$board->getDeletedAt() > 0 ? $board : null;
						}
					}

					return null;
				},
				$warnings,
			);

			return [
				'id' => (int)$board->getId(),
				'title' => (string)$board->getTitle(),
				'deleted' => true,
			] + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A deleted board leaves the boards of the caller.
	 */
	protected function readWith(): string {
		return 'deck_list_boards';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
