<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use Closure;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use Psr\Log\LoggerInterface;

/**
 * Backs `deck_delete_label`: deletes one label of a board the caller manages and its card associations.
 *
 * Irreversible in Deck, so the plan_state also binds the number of cards carrying the label: a card that receives it
 * after the preview needs a new approval. A deletion that throws is answered as done when the label is then absent from
 * the catalog of its board.
 */
final class DeleteLabelHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_delete_label';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private Closure $stateFactory) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @param string $userId Authenticated caller.
	 * @return array<string, mixed> Read-only plan with its plan_state.
	 * @throws \Throwable When the label is unavailable.
	 */
	public function preview(array $arguments, string $userId): array {
		return LabelPlans::plan(self::TOOL, $this->gateway->boardLabels()->labelState($userId, (int)$arguments['labelId']), null, $userId, $this->stateFactory);
	}

	/**
	 * @param array<string, mixed> $arguments Validated tool arguments with confirm and plan_state.
	 * @param string $userId Authenticated caller, who must manage the board.
	 * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$labelId = (int)$arguments['labelId'];
			$state = $this->gateway->boardLabels()->labelState($userId, $labelId);
			$plan = LabelPlans::plan(self::TOOL, $state, null, $userId, $this->stateFactory);
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				throw new PlanChanged($plan + ['warnings' => [['message' => DeckLabelMessages::planChanged()]]]);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			$warnings = [];
			$boardId = $state['boardId'];
			try {
				$this->afterWrite(
					function () use ($userId, $labelId, $state): bool {
						$this->gateway->boardLabels()->deleteLabel($userId, $labelId, LabelSnapshot::of($state, true));
						return true;
					},
					fn (): ?bool => in_array($labelId, array_column($this->gateway->boardLabels()->boardLabelState($userId, $boardId)['labels'], 'id'), true) ? null : true,
					$warnings,
				);
			} catch (DeckConflictException) {
				$fresh = $this->preview($arguments, $userId);
				throw new PlanChanged($fresh + ['warnings' => [['message' => DeckLabelMessages::planChanged()]]]);
			}
			return ['deleted' => true, 'label' => $state['label'], 'board' => $state['board']['name'], 'cards' => $state['cards']]
				+ ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 * @return string The catalog of the board no longer lists the label.
	 */
	protected function readWith(): string {
		return 'deck_list_labels';
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
