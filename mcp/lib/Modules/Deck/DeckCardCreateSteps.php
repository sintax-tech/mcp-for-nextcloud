<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Deck\Creation\CreateCardHandler;
use OCA\Mcp\Modules\Deck\Creation\CreateCardPreview;
use OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages;
use OCA\Mcp\Modules\Deck\Tools\CreateCard;
use OCA\Mcp\Platform\MailCards\DeckCardCreate;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;

/**
 * The card step of a mail card, through the very work classes of deck_create_card.
 *
 * The step is checked as the registry checks deck_create_card (grant deck.create, Guests and the Deck app by
 * {@see ToolAccess}, then the published schema); the execution context checks the Deck app again and the handler
 * checks the board of the stack, `confirm_shared` and every assignee against the board ACL, then creates the card
 * through Deck's own card service in the actor's session, as it always does. deck_create_card has no file argument:
 * the card gets no attachment and no share, only the reference written in its description.
 */
final class DeckCardCreateSteps implements DeckCardCreate {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param CreateCardPreview $plans the plan of deck_create_card
	 * @param DeckExecutionContext $execution the per-user effect of deck_create_card
	 */
	public function __construct(
		private ToolAccess $access,
		private CreateCardPreview $plans,
		private DeckExecutionContext $execution,
	) {
	}

	/** {@inheritDoc} */
	public function planCard(string $userId, array $card): array {
		if (!$this->allowed($userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed(CreateCard::definition()->name));
		}
		return $this->plans->plan(ArgumentValidator::validate(CreateCard::definition()->inputSchema, $card), $userId);
	}

	/** {@inheritDoc} */
	public function createCard(string $userId, array $card): WorkflowStep {
		if (!$this->allowed($userId)) {
			return WorkflowStep::failed(WorkflowMessages::notAllowed(CreateCard::definition()->name));
		}
		$arguments = ArgumentValidator::validate(CreateCard::definition()->inputSchema, $card);
		$result = $this->execution->execute(CreateCardHandler::TOOL, $arguments, $userId);
		$text = (string)($result['content'][0]['text'] ?? '');
		if (($result['isError'] ?? false) === true) {
			// AbstractHandler answers an error only for a refusal before the write or a write whose read-back found no
			// card; a card that may exist is answered as `confirmed: false` or `probable`, never as an error.
			return WorkflowStep::failed($text);
		}
		$created = json_decode($text, true);
		if (!is_array($created)) {
			return WorkflowStep::uncertain(WorkflowMessages::uncertain());
		}
		if (($created['requiresConfirmation'] ?? false) === true) {
			// A board of somebody else without confirm_shared: answered before anything is written.
			return WorkflowStep::failed((string)$created['message']);
		}
		$id = is_int($created['id'] ?? null) ? $created['id'] : null;
		if (($created['confirmed'] ?? true) !== true || $id === null) {
			$message = is_string($created['message'] ?? null) ? $created['message'] : DeckWriteMessages::probablyCreated();
			return WorkflowStep::uncertain($message, $id === null ? [] : ['cardId' => $id], $created);
		}
		$ids = ['cardId' => $id] + (is_int($created['boardId'] ?? null) ? ['boardId' => $created['boardId']] : []);
		$warnings = implode(' ', array_filter($created['warnings'] ?? [], 'is_string'));
		if (count($created['assignedUsers'] ?? []) < count(array_unique($arguments['assignees'] ?? []))) {
			return WorkflowStep::partial($ids, $created, $warnings);
		}
		return WorkflowStep::applied($ids, $created, $warnings === '' ? null : $warnings);
	}

	/**
	 * @param string $userId the actor
	 * @return bool whether the registry would let the actor call deck_create_card now
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when the grant cannot be read now
	 */
	private function allowed(string $userId): bool {
		return $this->access->allowed(CreateCard::definition()->toArray(), $userId);
	}
}
