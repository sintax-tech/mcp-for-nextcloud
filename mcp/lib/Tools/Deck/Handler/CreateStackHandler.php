<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\Tools\Deck\BoardBlueprint;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_stack`: a new list on a board the caller manages.
 *
 * The board decides whose it is; on somebody else's board nothing is written until the call repeats with
 * `confirm_shared: true`. A list found again only by its title is answered as `confirmed: "probable"`.
 */
final class CreateStackHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_stack';

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
	 * The payload is the created list.
	 *
	 * @param array<string, mixed> $arguments Requires `boardId` and `title`; accepts `order` and `confirm_shared`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws \InvalidArgumentException When the title is blank once trimmed, before any Deck lookup.
	 */
	public function handle(array $arguments, string $userId): array {
		$title = BoardBlueprint::title((string)$arguments['title'], 'title');

		return $this->run(function () use ($arguments, $title, $userId): array {
			$confirmation = $this->confirmShared($arguments, $userId, $this->gateway->boardOwnership($userId, (int)$arguments['boardId']));
			if ($confirmation !== null) {
				return $confirmation;
			}

			$boardId = (int)$arguments['boardId'];
			$order = array_key_exists('order', $arguments) ? (int)$arguments['order'] : null;

			// The lists before the write, so a creation that threw is told apart from a list that already had the title:
			// Deck inserts the list before its activity and the board event.
			$before = array_map(static fn ($stack): int => (int)$stack->getId(), $this->gateway->stacksOf($userId, $boardId));
			$warnings = [];
			$probable = false;
			$stack = $this->afterApproximateWrite(
				fn () => $this->gateway->createStack($userId, $boardId, $title, $order),
				fn () => self::newStack($this->gateway->stacksOf($userId, $boardId), $title, $before),
				$warnings,
				$probable,
			);

			return ($probable ? ['confirmed' => 'probable'] : []) + [
				'id' => (int)$stack->getId(),
				'boardId' => (int)$stack->getBoardId(),
				'title' => (string)$stack->getTitle(),
				'order' => (int)$stack->getOrder(),
			] + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A list is found again among the lists of its board.
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
