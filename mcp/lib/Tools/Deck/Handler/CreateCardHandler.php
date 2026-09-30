<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_card`.
 *
 * The card always belongs to the caller: assigning it to somebody else would need their board ACL
 * resolved too, and it is out of scope for this sprint.
 */
final class CreateCardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_card';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts the created Deck card into the tool payload.
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
	 * The payload is the created card.
	 *
	 * @param array<string, mixed> $arguments Requires `stackId` and `title`; accepts `description` and `duedate`.
	 * @param string $userId UID of the authenticated caller, also the card owner.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			// The stack decides whose board the card lands on; without the user's confirmation the
			// answer is the shared-resource payload and nothing is created.
			$confirmation = $this->confirmShared(
				$arguments,
				$userId,
				$this->gateway->stackOwnership($userId, (int)$arguments['stackId']),
			);
			if ($confirmation !== null) {
				return $confirmation;
			}

			$card = $this->gateway->createCard(
				$userId,
				(int)$arguments['stackId'],
				CardInput::requireTitle((string)$arguments['title']),
				CardInput::requireDescription((string)($arguments['description'] ?? '')),
				CardInput::duedate($arguments['duedate'] ?? null),
			);

			return $this->formatter->card($card);
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
