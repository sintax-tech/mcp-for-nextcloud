<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck\Handler;

use Closure;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCA\Mcp\Tools\Deck\DeckConflictException;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckMessages;
use OCA\Mcp\Tools\Deck\DependencyChange;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;
use Throwable;

/** Changes one native relation, with a bounded readable cycle check and mandatory state-bound confirmation. */
final class ChangeCardDependencyHandler extends AbstractHandler {
	public const ADD = 'deck_add_card_dependency';
	public const REMOVE = 'deck_remove_card_dependency';

	/**
	 * @param DeckGatewayInterface $gateway Fresh native authorization boundary.
	 * @param LoggerInterface $logger Logs exception classes, never native messages.
	 * @param CardFormatter $formatter Safe readable references.
	 * @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	 * @param bool $assign Add when true; otherwise remove.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger,
		private CardFormatter $formatter, private Closure $stateFactory, private bool $assign) {
		parent::__construct($gateway, $logger);
	}

	/**
	 * @param array<string, mixed> $arguments Validated cardId/targetId request.
	 * @param string $userId Authenticated caller.
	 * @return array<string, mixed> Read-only plan; no hidden relation indicators.
	 * @throws Throwable On denial, uncertain traversal or detectable cycle.
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->plan($this->state($arguments, $userId), $userId);
	}

	/** {@inheritDoc} The registry alone owns the no-confirm preview gate. */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$state = $this->state($arguments, $userId);
			$plan = $this->plan($state, $userId);
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				throw new PlanChanged($plan + ['warnings' => [['message' => DeckMessages::dependencyPlanChanged()]]]);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			if ($state['present'] === $this->assign) {
				return $this->result($state, false);
			}
			$error = null;
			try {
				$this->gateway->changeDependency($userId, (int)$arguments['cardId'], (int)$arguments['targetId'],
					$this->assign, DependencyChange::snapshot($state));
			} catch (DeckConflictException) {
				throw new PlanChanged($this->preview($arguments, $userId)
					+ ['warnings' => [['message' => DeckMessages::dependencyPlanChanged()]]]);
			} catch (Throwable $e) {
				$error = $e;
			}
			try {
				$observed = $this->state($arguments, $userId, false);
			} catch (Throwable $e) {
				$this->logger->warning('MCP Deck dependency result unknown ({exception})', ['exception' => $e::class]);
				// Revoked targets are never echoed back from the pre-write snapshot.
				return ['confirmed' => false, 'message' => DeckMessages::dependencyUnconfirmed()];
			}
			if ($observed['present'] !== $this->assign) {
				if ($error !== null && DependencyChange::snapshot($state, false) === DependencyChange::snapshot($observed, false)) {
					throw $error;
				}
				return ['confirmed' => false, 'message' => DeckMessages::dependencyUnconfirmed()];
			}
			$result = $this->result($observed, true);
			if ($error !== null) {
				$this->logger->warning('MCP Deck dependency state observed after failure ({exception})', ['exception' => $error::class]);
				$result['warnings'] = [DeckMessages::dependencyStateObserved()];
			}
			return $result;
		});
	}

	/**
	 * @param array<string, mixed> $arguments Validated card IDs.
	 * @param string $userId Authenticated caller.
	 * @param bool $checkCycle False only for post-write observation.
	 * @return array<string, mixed> Fresh authorized state.
	 * @throws Throwable On validation, ACL or traversal failure.
	 */
	private function state(array $arguments, string $userId, bool $checkCycle = true): array {
		return $this->gateway->dependencyChangeState($userId, (int)$arguments['cardId'],
			(int)$arguments['targetId'], $this->assign, $checkCycle);
	}

	/**
	 * @param array<string, mixed> $state Fresh state containing only readable relations.
	 * @param string $userId Caller whose plan is fingerprinted.
	 * @return array<string, mixed> Only the requested readable edge is displayed.
	 */
	private function plan(array $state, string $userId): array {
		$board = $state['board'] + ['shared' => $state['board']['owner'] !== $userId];
		return $this->references($state) + ['action' => $this->toolName(), 'board' => $board,
			'before' => $state['present'], 'after' => $this->assign, 'shared' => $board['shared'] ? [$board] : [],
			'message' => DeckMessages::dependencyWriteLimitations(), 'recoverable' => true,
			PlanState::ARGUMENT => ($this->stateFactory)()->of($this->toolName(), $userId, DependencyChange::snapshot($state))];
	}

	/**
	 * @param array<string, mixed> $state Current readable state.
	 * @return array<string, mixed> Safe direction and references.
	 */
	private function references(array $state): array {
		$ref = fn (array $node): array => $this->formatter->reference($node['card'], $node['boardId']);
		return ['origin' => $ref($state['origin']), 'target' => $ref($state['target']),
			'direction' => 'origin.dependentCards contains target'];
	}

	/**
	 * @param array<string, mixed> $state Current readable state.
	 * @param bool $changed Whether a native write was attempted and the desired state observed.
	 * @return array<string, mixed> Observation, not an authorship claim.
	 */
	private function result(array $state, bool $changed): array {
		return $this->references($state) + ['confirmed' => true, 'changed' => $changed,
			'present' => $state['present'], 'verification' => 'observed-state',
			'message' => DeckMessages::dependencyWriteLimitations()];
	}

	/** {@inheritDoc} */
	protected function toolName(): string {
		return $this->assign ? self::ADD : self::REMOVE;
	}
}
