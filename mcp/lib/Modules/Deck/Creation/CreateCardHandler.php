<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Creation;

use Closure;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Deck\Labels\CardLabelPlan;
use OCA\Mcp\Modules\Deck\Labels\CardLabelWriter;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Editing\CardInput;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_create_card`.
 *
 * The caller owns the card; optional assignees are checked against Deck board ACLs before creation. A creation or
 * assignment that throws after Deck saved it is read back and answered as saved, with a warning
 * ({@see AbstractHandler::afterWrite()}). A card found again only by owner, title and time is answered as
 * `confirmed: "probable"` and gets no assignment ({@see AbstractHandler::afterApproximateWrite()}).
 */
final class CreateCardHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_create_card';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param CardFormatter $formatter Converts the created Deck card into the tool payload.
	 * @param Closure(): bool|null $mayCreateLabels Whether the caller holds deck.labels for every labels request.
	 * @param Closure(array,string):array|null $approvedPlan Fresh signed plan for labels requests.
	 */
	public function __construct(
		DeckGatewayInterface $gateway,
		LoggerInterface $logger,
		private CardFormatter $formatter,
		private ?Closure $mayCreateLabels = null,
		private ?Closure $approvedPlan = null,
	) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The payload is the created card.
	 *
	 * @param array<string, mixed> $arguments Requires `stackId` and `title`; accepts `description`, `duedate`, `assignees` and `labels`.
	 * @param string $userId UID of the authenticated caller, also the card owner.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			if (array_key_exists('labels', $arguments) && !($this->mayCreateLabels ?? static fn (): bool => false)()) {
				throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages::creationNeedsGrant([]));
			}
			if (array_key_exists('labels', $arguments) && $this->approvedPlan !== null) {
				\OCA\Mcp\Modules\Deck\Labels\LabelCreationPlan::require($arguments, ($this->approvedPlan)($arguments, $userId));
			}
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

			// Labels are resolved, and their creation admitted, before the card exists: a refusal leaves nothing behind.
			$labelPlan = CardLabelPlan::prepare($this->gateway, $arguments, $userId, $this->mayCreateLabels ?? static fn (): bool => false);

			$stackId = (int)$arguments['stackId'];
			$title = CardInput::requireTitle((string)$arguments['title']);
			$description = CardInput::requireDescription((string)($arguments['description'] ?? ''));
			$duedate = CardInput::duedate($arguments['duedate'] ?? null, $this->formatter->timezone());

			$warnings = [];
			// Deck inserts the card before its activity, events and enrichment: a failure after that point is a card
			// that exists, found again by owner, title and creation time.
			$probable = false;
			$card = $this->afterApproximateWrite(
				function () use ($userId, $stackId, $title, $description, $duedate, $arguments): object {
					if (array_key_exists('labels', $arguments)) {
						\OCA\Mcp\Modules\Deck\Labels\LabelGrantGate::require($this->mayCreateLabels);
					}
					return $this->gateway->createCard($userId, $stackId, $title, $description, $duedate);
				},
				fn () => $this->gateway->findCreatedCard($userId, $stackId, $title, []),
				$warnings,
				$probable,
			);

			if ($probable) {
				// Found by likeness, so maybe somebody else's card: nothing is written on it.
				if ($assignees !== []) {
					$warnings[] = \OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages::probableCardNotAssigned();
				}
				if ($labelPlan !== null) {
					$warnings[] = \OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages::probableCardNoLabels();
				}

				return ['confirmed' => 'probable'] + $this->formatter->card($card) + ['warnings' => $warnings];
			}

			$assignmentFailed = false;
			$writes = [];
			foreach ($assignees as $index => $assignee) {
				$writes[$index] = function (bool $deferDelivery) use ($userId, $card, $assignee, &$warnings): mixed {
					return $this->afterWrite(
						fn () => $this->gateway->assignCardUser($userId, (int)$card->getId(), $assignee['uid'], $deferDelivery),
						fn () => \OCA\Mcp\Modules\Deck\Integration\CardFormatter::assignmentOf($this->gateway->cardAssignments($userId, (int)$card->getId()), $assignee['uid']),
						$warnings,
					);
				};
			}
			foreach (\OCA\Mcp\Modules\Deck\AssigneePasses::run($writes) as $outcome) {
				if ($outcome instanceof \Throwable) {
					// Creation succeeded: never turn this into an error that encourages a duplicate retry.
					$this->logger->warning('MCP Deck assignment failed after creation ({exception})', ['exception' => $outcome::class]);
					$assignmentFailed = true;
					continue;
				}
				$card->setAssignedUsers([...($card->getAssignedUsers() ?? []), $outcome]);
			}
			if ($assignmentFailed) {
				$warnings[] = Translator::t('The card was created, but one or more assignees could not be assigned. Read the card before retrying assignment in Deck.');
			}
			$labels = [];
			if ($labelPlan !== null) {
				$written = (new CardLabelWriter($this->gateway, $this->logger, $this->mayCreateLabels))->apply($userId, $stackId, (int)$card->getId(), $labelPlan);
				$labels = ['labels' => $written['labels']] + ($written['created'] === [] ? [] : ['labelsCreated' => $written['created']]);
				$warnings = [...$warnings, ...$written['warnings']];
			}
			return $this->formatter->card($card) + $labels + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 *
	 * A new card is found again among the cards of its list.
	 * @return string unchanged result of this operation
	 */
	protected function readWith(): string {
		return 'deck_list_cards';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
