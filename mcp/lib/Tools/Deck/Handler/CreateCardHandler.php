<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_card`.
 *
 * The caller owns the card; optional assignees are checked against Deck board ACLs before creation. A creation or
 * assignment that throws after Deck saved it is read back and answered as saved, with a warning
 * ({@see AbstractHandler::afterWrite()}). A card found again only by owner, title and time is answered as
 * `confirmed: "probable"` and gets no assignment ({@see AbstractHandler::afterApproximateWrite()}).
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

			$stackId = (int)$arguments['stackId'];
			$title = CardInput::requireTitle((string)$arguments['title']);
			$description = CardInput::requireDescription((string)($arguments['description'] ?? ''));
			$duedate = CardInput::duedate($arguments['duedate'] ?? null);

			$warnings = [];
			// Deck inserts the card before its activity, events and enrichment: a failure after that point is a card
			// that exists, found again by owner, title and creation time.
			$probable = false;
			$card = $this->afterApproximateWrite(
				fn () => $this->gateway->createCard($userId, $stackId, $title, $description, $duedate),
				fn () => $this->gateway->findCreatedCard($userId, $stackId, $title, []),
				$warnings,
				$probable,
			);

			if ($probable) {
				// Found by likeness, so maybe somebody else's card: nothing is written on it.
				if ($assignees !== []) {
					$warnings[] = DeckMessages::probableCardNotAssigned();
				}

				return ['confirmed' => 'probable'] + $this->formatter->card($card) + ['warnings' => $warnings];
			}

			$assignmentFailed = false;
			foreach ($assignees as $assignee) {
				try {
					$assignment = $this->afterWrite(
						fn () => $this->gateway->assignCardUser($userId, (int)$card->getId(), $assignee['uid']),
						fn () => $this->assignmentOf($userId, (int)$card->getId(), $assignee['uid']),
						$warnings,
					);
					$card->setAssignedUsers([...($card->getAssignedUsers() ?? []), $assignment]);
				} catch (\Throwable $e) {
					// Creation succeeded: never turn this into an error that encourages a duplicate retry.
					$this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $e::class]);
					$assignmentFailed = true;
				}
			}
			if ($assignmentFailed) {
				$warnings[] = Translator::t('The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.');
			}
			return $this->formatter->card($card) + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A new card is found again among the cards of its list.
	 */
	protected function readWith(): string {
		return 'deck_list_cards';
	}

	/**
	 * {@inheritDoc}
	 *
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
