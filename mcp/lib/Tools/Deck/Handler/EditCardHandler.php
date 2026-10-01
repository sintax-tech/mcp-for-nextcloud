<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use InvalidArgumentException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Deck\AssigneeChanges;
use OCA\Mcp\Tools\Deck\CardCriteria;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\CardInput;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckErrors;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_edit_card`.
 *
 * Deck v1.17.5 only offers a whole-form update, so this handler reads the card, merges what the
 * client sent and writes everything back; the gateway fills in the fields the client did not
 * touch. When `lastModified` is sent and no longer matches, nothing is written. Responsibles are
 * changed afterwards, one account at a time, through the assignment service.
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
	 * The payload is the updated card. When `assign` or `unassign` were sent it also reports `assigned`,
	 * `unassigned` and `failed` (uid and a safe reason); a failure in the middle leaves what was already done.
	 *
	 * @param array<string, mixed> $arguments Requires `cardId`; accepts the editable fields, `assign`, `unassign` and `lastModified`.
	 * @param string $userId UID of the authenticated caller.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 * @throws InvalidArgumentException When nothing editable is present, a date is invalid or an assignee is refused, mapped to -32602.
	 */
	public function handle(array $arguments, string $userId): array {
		$given = array_intersect_key($arguments, array_flip(self::EDITABLE_FIELDS));
		$changes = AssigneeChanges::fromArguments($arguments);
		$editsAssignees = $changes['assign'] !== [] || $changes['unassign'] !== [];
		if ($given === [] && !$editsAssignees) {
			throw new InvalidArgumentException(DeckMessages::errorNoFieldToEdit());
		}

		return $this->run(function () use ($arguments, $given, $changes, $editsAssignees, $userId): array {
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

			// Everything that can be refused is refused here, before the first write.
			$title = array_key_exists('title', $given)
				? CardInput::requireTitle((string)$given['title'])
				: (string)$card->getTitle();
			$description = array_key_exists('description', $given)
				? CardInput::requireDescription((string)$given['description'])
				: (string)$card->getDescription();
			$duedate = array_key_exists('duedate', $given)
				? CardInput::duedate($given['duedate'])
				: $this->currentDuedate($card->getDuedate());
			$resolved = AssigneeChanges::resolve($changes['assign'], $changes['unassign'], CardCriteria::assignedUids($card));
			if ($resolved['add'] !== []) {
				$this->gateway->validateAssignees($userId, (int)$card->getStackId(), $resolved['add']);
			}

			if ($given === []) {
				$updated = $card;
			} else {
				$updated = $this->gateway->updateCard($userId, $card, $title, $description, $duedate);
				// The update answer does not always carry the assignments; the ones read above still stand.
				if ($updated->getAssignedUsers() === null) {
					$updated->setAssignedUsers($card->getAssignedUsers() ?? []);
				}
			}
			if (!$editsAssignees) {
				return $this->formatter->card($updated);
			}

			// Applied first: the card is formatted from the assignments that really stand afterwards.
			$report = $this->applyAssignees($userId, $updated, $resolved);

			return $this->formatter->card($updated) + $report;
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
	 * Assigns and unassigns one account at a time and reports what happened.
	 *
	 * A failure is recorded and the loop goes on: nothing already done is undone, and the card is
	 * mutated in place only for what succeeded, so the payload shows the real assignees.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @param Card $card Card to report, its assignments updated as each change succeeds.
	 * @param array{add: list<string>, remove: list<string>, alreadyAssigned: list<string>, notAssigned: list<string>} $resolved What to do and what to ignore.
	 * @return array{assigned: list<string>, unassigned: list<string>, failed: list<array{uid: string, reason: string}>, warnings?: list<string>}
	 */
	private function applyAssignees(string $userId, Card $card, array $resolved): array {
		$assigned = [];
		$unassigned = [];
		$failed = [];
		$cardId = (int)$card->getId();
		$assignments = $card->getAssignedUsers() ?? [];

		foreach ($resolved['remove'] as $uid) {
			try {
				$this->gateway->unassignCardUser($userId, $cardId, $uid);
				$unassigned[] = $uid;
				$assignments = array_values(array_filter(
					$assignments,
					static fn ($assignment): bool => !($assignment->getType() === Acl::PERMISSION_TYPE_USER && (string)$assignment->getParticipant() === $uid),
				));
			} catch (\Throwable $e) {
				$failed[] = $this->failure($uid, $e);
			}
		}
		foreach ($resolved['add'] as $uid) {
			try {
				$assignments[] = $this->gateway->assignCardUser($userId, $cardId, $uid);
				$assigned[] = $uid;
			} catch (\Throwable $e) {
				$failed[] = $this->failure($uid, $e);
			}
		}
		$card->setAssignedUsers($assignments);

		$warnings = [];
		if ($resolved['notAssigned'] !== []) {
			$warnings[] = Translator::t('Ignored, not assigned to the card: %s', [implode(', ', $resolved['notAssigned'])]);
		}
		if ($resolved['alreadyAssigned'] !== []) {
			$warnings[] = Translator::t('Ignored, already assigned to the card: %s', [implode(', ', $resolved['alreadyAssigned'])]);
		}

		return ['assigned' => $assigned, 'unassigned' => $unassigned, 'failed' => $failed]
			+ ($warnings === [] ? [] : ['warnings' => $warnings]);
	}

	/**
	 * @param string $uid Account whose change failed.
	 * @param \Throwable $e What Deck raised; only its class is logged, never its message.
	 * @return array{uid: string, reason: string} Entry of `failed`, worded by {@see DeckErrors}.
	 */
	private function failure(string $uid, \Throwable $e): array {
		$this->logger->warning('MCP Deck assignment change failed ({exception})', ['exception' => $e::class]);

		return ['uid' => $uid, 'reason' => DeckErrors::messageFor($e)];
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
