<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_card`.
 *
 * The caller owns the card; optional assignees are checked against Deck board ACLs before creation.
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
	 * @param array<string, mixed> $arguments Requires `stackId` and `title`; accepts `description`, `duedate` and `assignees`.
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

			$assignees = array_key_exists('assignees', $arguments)
				? $this->gateway->validateAssignees($userId, (int)$arguments['stackId'], CardInput::assignees($arguments['assignees']))
				: [];

			$card = $this->gateway->createCard(
				$userId,
				(int)$arguments['stackId'],
				CardInput::requireTitle((string)$arguments['title']),
				CardInput::requireDescription((string)($arguments['description'] ?? '')),
				CardInput::duedate($arguments['duedate'] ?? null),
			);

			$warnings = [];
			foreach ($assignees as $assignee) {
				try {
					$assignment = $this->gateway->assignCardUser($userId, (int)$card->getId(), $assignee['uid']);
					$card->setAssignedUsers([...($card->getAssignedUsers() ?? []), $assignment]);
				} catch (\Throwable $e) {
					// Creation succeeded: never turn this into an error that encourages a duplicate retry.
					$this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
					$warnings = [Translator::t('The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.')];
				}
			}
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
