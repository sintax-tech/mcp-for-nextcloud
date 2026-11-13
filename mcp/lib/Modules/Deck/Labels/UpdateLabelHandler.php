<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
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
 * Backs `deck_update_label`: renames or recolours one label of a board the caller manages.
 *
 * The write needs the plan_state of a fresh plan; the gateway compares the label once more right before the native
 * update. An update that throws is answered as saved when the label then reads back with the approved title and colour.
 */
final class UpdateLabelHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'deck_update_label';

	/**
	 * @param DeckGatewayInterface $gateway Deck access, the only door to the Deck app.
	 * @param LoggerInterface $logger Receives tool name and exception class on failure.
	 * @param \Closure():bool|null $granted Registered policy reader, checked again at the native effect.
	 * @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger, private Closure $stateFactory, private ?Closure $granted = null) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @param string $userId Authenticated caller.
	 * @return array<string, mixed> Read-only plan with its plan_state.
	 * @throws \Throwable When validation or the native access check fails.
	 */
	public function preview(array $arguments, string $userId): array {
		$state = $this->gateway->boardLabels()->labelState($userId, (int)$arguments['labelId']);
		return LabelPlans::plan(self::TOOL, $state, LabelPlans::updated($arguments, $state), $userId, $this->stateFactory);
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
			$after = LabelPlans::updated($arguments, $state);
			$plan = LabelPlans::plan(self::TOOL, $state, $after, $userId, $this->stateFactory);
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				throw new PlanChanged($plan + ['warnings' => [['message' => DeckLabelMessages::planChanged()]]]);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			if (!$plan['changed']) {
				return ['label' => $state['label'], 'changed' => false, 'message' => DeckLabelMessages::unchanged()];
			}
			$warnings = [];
			try {
				$label = $this->afterWrite(
					function () use ($userId, $labelId, $after, $state): array {
						LabelGrantGate::require($this->granted);
						return $this->gateway->boardLabels()->updateLabel($userId, $labelId, $after['title'], $after['color'], LabelSnapshot::of($state, false));
					},
					function () use ($userId, $labelId, $after): ?array {
						$label = $this->gateway->boardLabels()->labelState($userId, $labelId)['label'];
						return ['title' => $label['title'], 'color' => $label['color']] === $after ? $label : null;
					},
					$warnings,
				);
			} catch (DeckConflictException) {
				$fresh = $this->preview($arguments, $userId);
				throw new PlanChanged($fresh + ['warnings' => [['message' => DeckLabelMessages::planChanged()]]]);
			}
			return ['label' => $label, 'changed' => true, 'cards' => $state['cards']] + ($warnings === [] ? [] : ['warnings' => $warnings]);
		});
	}

	/**
	 * {@inheritDoc}
	 * @return string The catalog of the board shows the label.
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
