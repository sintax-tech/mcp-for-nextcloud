<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_edit_card`.
 *
 * Deck v1.17.5 only offers a whole-form update, so this handler reads the card, merges what the
 * client sent and writes everything back; the gateway fills in the fields the client did not
 * touch. When `lastModified` is sent and no longer matches, nothing is written.
 */
final class EditCardHandler extends AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_edit_card';

	/** Fields the client may change; one of them is required. */
	private const EDITABLE_FIELDS = ['title', 'description', 'duedate'];

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts the updated Deck card into the tool payload.
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
	 * The payload is the updated card.
	 *
	 * @param array<string, mixed> $arguments Requires `cardId`; accepts the editable fields and `lastModified`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException When no editable field is present or a date is invalid, mapped to -32602.
	 */
	public function handle(array $arguments, string $userId): array {
		$given = array_intersect_key($arguments, array_flip(self::EDITABLE_FIELDS));
		if ($given === []) {
			throw new InvalidArgumentException(DeckMessages::errorNoFieldToEdit());
		}

		return $this->run(function () use ($arguments, $given, $userId): array {
			// Owned by somebody else and not confirmed yet: answer with the shared-resource payload
			// and leave the card untouched, before it is even read.
			$confirmation = $this->confirmShared(
				$arguments,
				$userId,
				$this->gateway->cardOwnership($userId, (int)$arguments['cardId']),
			);
			if ($confirmation !== null) {
				return $confirmation;
			}

			$card = $this->gateway->findCard($userId, (int)$arguments['cardId']);

			$expected = $arguments['lastModified'] ?? null;
			if ($expected !== null && (int)$expected !== $card->getLastModified()) {
				throw new DeckConflictException(DeckMessages::errorConflict());
			}

			$title = array_key_exists('title', $given)
				? CardInput::requireTitle((string)$given['title'])
				: (string)$card->getTitle();
			$description = array_key_exists('description', $given)
				? CardInput::requireDescription((string)$given['description'])
				: (string)$card->getDescription();
			$duedate = array_key_exists('duedate', $given)
				? CardInput::duedate($given['duedate'])
				: $this->currentDuedate($card->getDuedate());

			return $this->formatter->card(
				$this->gateway->updateCard($userId, $card, $title, $description, $duedate),
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

	/**
	 * Current due date of a card, kept to the second.
	 *
	 * Sending back only the day would reset the time set in the Deck web interface to midnight on
	 * every edit of the title or description, so the full instant goes back unchanged.
	 *
	 * @param mixed $duedate Value of `Card::getDuedate()`.
	 * @return string|null ISO 8601 timestamp, or null when the card has no date.
	 */
	private function currentDuedate(mixed $duedate): ?string {
		return $duedate instanceof \DateTimeInterface ? $duedate->format(\DateTimeInterface::ATOM) : null;
	}
}
