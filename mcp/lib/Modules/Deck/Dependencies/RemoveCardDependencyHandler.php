<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

use Closure;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Dependencies\DependencyChange;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;
use Throwable;

/** Changes one native relation, with a bounded readable cycle check and mandatory state-bound confirmation. */
final class RemoveCardDependencyHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const TOOL = 'deck_remove_card_dependency';

	/**
	 * @param DeckGatewayInterface $gateway Fresh native authorization boundary.
	 * @param LoggerInterface $logger Logs exception classes, never native messages.
	 * @param CardFormatter $formatter Safe readable references.
	 * @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger,
		private CardFormatter $formatter, private Closure $stateFactory) {
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

	/**
	 * {@inheritDoc} The registry alone owns the no-confirm preview gate.
	 * @param array $arguments validated tool arguments
	 * @param string $userId authenticated caller UID
	 * @return array unchanged result of this operation
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$state = $this->state($arguments, $userId);
			$plan = $this->plan($state, $userId);
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				throw new PlanChanged($plan + ['warnings' => [['message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyPlanChanged()]]]);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			if ($state['present'] === false) {
				return $this->result($state, false);
			}
			$error = null;
			try {
				$this->gateway->changeDependency($userId, (int)$arguments['cardId'], (int)$arguments['targetId'],
					false, DependencyChange::snapshot($state));
			} catch (DeckConflictException) {
				throw new PlanChanged($this->preview($arguments, $userId)
					+ ['warnings' => [['message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyPlanChanged()]]]);
			} catch (Throwable $e) {
				$error = $e;
			}
			try {
				$observed = $this->state($arguments, $userId, false);
			} catch (Throwable $e) {
				$this->logger->warning('MCP Deck dependency result unknown ({exception})', ['exception' => $e::class]);
				// Revoked targets are never echoed back from the pre-write snapshot.
				return ['confirmed' => false, 'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyUnconfirmed()];
			}
			if ($observed['present'] !== false) {
				if ($error !== null && DependencyChange::snapshot($state, false) === DependencyChange::snapshot($observed, false)) {
					throw $error;
				}
				return ['confirmed' => false, 'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyUnconfirmed()];
			}
			$result = $this->result($observed, true);
			if ($error !== null) {
				$this->logger->warning('MCP Deck dependency state observed after failure ({exception})', ['exception' => $error::class]);
				$result['warnings'] = [\OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyStateObserved()];
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
			(int)$arguments['targetId'], false, $checkCycle);
	}

	/**
	 * @param array<string, mixed> $state Fresh state containing only readable relations.
	 * @param string $userId Caller whose plan is fingerprinted.
	 * @return array<string, mixed> Only the requested readable edge is displayed.
	 */
	private function plan(array $state, string $userId): array {
		return RemoveCardDependencyPreview::fromState($state, $userId, $this->formatter, $this->stateFactory);
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
			'message' => \OCA\Mcp\Modules\Deck\Presentation\DeckDependencyMessages::dependencyWriteLimitations()];
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
