<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Deck\Db\Stack;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_list_stacks`.
 *
 * `StackService::findAll()` checks read access on the board before listing, and returns each stack
 * with its cards embedded; the handler only counts those cards so the payload does not repeat what
 * `deck_list_cards` already returns.
 */
final class ListStacksHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_list_stacks';

	/** Hard ceiling on the answer size. */
	public const MAX_STACKS = 200;

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts Deck stacks into the tool payload.
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
	 * The payload is `{stacks: list<{id, boardId, title, order, cardsCount}>, truncated: bool}`,
	 * with at most {@see self::MAX_STACKS} stacks.
	 *
	 * @param array<string, mixed> $arguments Requires `boardId`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$stacks = $this->gateway->listStacks($userId, (int)$arguments['boardId']);
			$truncated = count($stacks) > self::MAX_STACKS;
			$stacks = array_slice($stacks, 0, self::MAX_STACKS);

			return [
				'stacks' => array_map(fn (Stack $stack) => $this->formatter->stack($stack), $stacks),
				'truncated' => $truncated,
			];
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
