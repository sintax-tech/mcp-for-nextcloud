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
 * `confirm_shared: true`.
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

			$order = array_key_exists('order', $arguments) ? (int)$arguments['order'] : null;
			$stack = $this->gateway->createStack($userId, (int)$arguments['boardId'], $title, $order);

			return [
				'id' => (int)$stack->getId(),
				'boardId' => (int)$stack->getBoardId(),
				'title' => (string)$stack->getTitle(),
				'order' => (int)$stack->getOrder(),
			];
		});
	}

	/**
	 * {@inheritDoc}
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
