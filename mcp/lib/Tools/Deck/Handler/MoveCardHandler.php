<?php
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
			$order = array_key_exists('order', $arguments) ? (int)$arguments['order'] : null;

			return $this->formatter->card(
				$this->gateway->moveCard($userId, (int)$arguments['cardId'], (int)$arguments['stackId'], $order),
			);
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
