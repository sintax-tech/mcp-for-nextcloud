<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

use Closure;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyChange;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Prepares the existing remove relation plan without effects. */
final class RemoveCardDependencyPreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context per-call composition and failure boundary */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged plan with its caller timezone
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview(RemoveCardDependencyHandler::TOOL, fn (): array => $this->prepare($arguments, $userId), $userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId caller UID
	 * @return array<string,mixed> fresh signed plan
	 */
	private function prepare(array $arguments, string $userId): array {
		$state = $this->context->gateway()->dependencyChangeState($userId, (int)$arguments['cardId'], (int)$arguments['targetId'], false, true);
		return self::fromState($state, $userId, $this->context->formatter($userId), fn () => $this->context->planState());
	}

	/**
	 * @param array<string,mixed> $state authorized readable relation state
	 * @param string $userId caller UID
	 * @param CardFormatter $formatter caller formatter
	 * @param Closure():PlanState $stateFactory lazy HMAC
	 * @return array<string,mixed> unchanged signed dependency plan
	 */
	public static function fromState(array $state, string $userId, CardFormatter $formatter, Closure $stateFactory): array {
		$board = $state['board'] + ['shared' => $state['board']['owner'] !== $userId];
		return self::references($state, $formatter) + ['action' => RemoveCardDependencyHandler::TOOL, 'board' => $board,
			'before' => $state['present'], 'after' => false, 'shared' => $board['shared'] ? [$board] : [],
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyWriteLimitations(), 'recoverable' => true,
			PlanState::ARGUMENT => ($stateFactory)()->of(RemoveCardDependencyHandler::TOOL, $userId, DependencyChange::snapshot($state))];
	}

	/**
	 * @param array<string,mixed> $state readable edge
	 * @param CardFormatter $formatter caller formatter
	 * @return array<string,mixed> unchanged safe direction and references
	 */
	public static function references(array $state, CardFormatter $formatter): array {
		$ref = fn (array $node): array => $formatter->reference($node['card'], $node['boardId']);
		return ['origin' => $ref($state['origin']), 'target' => $ref($state['target']),
			'direction' => 'origin.dependentCards contains target'];
	}
}
