<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use Closure;
use OCA\Mcp\Modules\Deck\Editing\CardLabelChanges;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Prepares the same incremental label plan and receipt. */
final class EditCardLabelsPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context per-call composition and failure boundary */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged plan with its caller timezone
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(EditCardLabelsHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	* @param array<string, mixed> $arguments Validated tool arguments.
	* @param string $userId Authenticated caller.
	* @return array<string, mixed> Read-only before/after plan.
	* @throws \Throwable When validation or the native access check fails.
	*/
	private function prepare(array $arguments, string $userId): array {
		$changes = CardLabelChanges::parse($arguments);
		$state = $this->context->gateway()->cardLabelState($userId, (int)$arguments['cardId'], CardLabelChanges::requested($changes));
		return self::fromState($state, $changes, $userId, fn () => $this->context->planState());
	}

	/**
	 * @param array<string,mixed> $state current readable label state
	 * @param array{add:list<int>,remove:list<int>} $changes validated IDs
	 * @param string $userId caller UID
	 * @param Closure():PlanState $stateFactory lazy HMAC
	 * @return array<string,mixed> unchanged signed label plan
	 */
	public static function fromState(array $state, array $changes, string $userId, Closure $stateFactory): array {
		$snapshot = CardLabelChanges::snapshot($state);
		$delta = CardLabelChanges::delta($state, $changes);
		$board = $state['board'] + ['shared' => $state['board']['owner'] !== $userId];
		return ['action' => EditCardLabelsHandler::TOOL, 'card' => $snapshot['card'],
			'board' => $board, 'before' => $state['labels'], 'after' => $delta['after'],
			'add' => $delta['add'], 'remove' => $delta['remove'], 'unchanged' => $delta['unchanged'],
			'shared' => $board['owner'] === $userId ? [] : [$board], 'recoverable' => true,
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckPlanMessages::planLabels(),
			PlanState::ARGUMENT => ($stateFactory)()->of(EditCardLabelsHandler::TOOL, $userId,
				['requested' => $changes, 'state' => $snapshot])];
	}
}
