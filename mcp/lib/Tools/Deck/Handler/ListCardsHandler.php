<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Deck\Db\Card;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_list_cards`.
 *
 * Deck has no service method for listing cards, so the gateway checks read access on the stack and
 * queries the mapper. A deleted or archived card can still reach this handler; the filter here is
 * what guarantees it never reaches the client.
 */
final class ListCardsHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_list_cards';

	/** Default page size when the client does not ask for one. */
	public const DEFAULT_LIMIT = 50;

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts Deck cards into the tool payload and decides visibility.
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
	 * The payload is `{cards: list<card>, limit: int, offset: int, total: int|null}`, where `total`
	 * is only known when the page came back short (cards before the offset plus the page). An empty page
	 * past the first one leaves it null: the stack ends somewhere before the offset, and the offset is not it.
	 *
	 * @param array<string, mixed> $arguments Requires `stackId`; accepts `limit` and `offset`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
			$offset = (int)($arguments['offset'] ?? 0);
			$page = $this->gateway->listCards($userId, (int)$arguments['stackId'], $limit, $offset);

			$cards = array_values(array_filter(
				$page['items'],
				fn (Card $card) => $this->formatter->isListed($card),
			));

			return [
				'cards' => array_map(fn (Card $card) => $this->formatter->card($card, $page['boardId']), $cards),
				'limit' => $limit,
				'offset' => $offset,
				// Deck skips deleted and archived cards in the query itself, so the offset counts listed cards:
				// a page that came back short ends the stack, and the total is what precedes it plus the page.
				// With no card on it past the first page, what precedes it is not known.
				'total' => count($page['items']) < $limit && ($offset === 0 || $cards !== []) ? $offset + count($cards) : null,
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
