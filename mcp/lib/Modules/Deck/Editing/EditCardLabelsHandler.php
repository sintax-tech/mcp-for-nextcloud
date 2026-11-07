<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use Closure;
use OCA\Mcp\Modules\Deck\Integration\CardFormatter;
use OCA\Mcp\Modules\Deck\Editing\CardLabelChanges;
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use Psr\Log\LoggerInterface;
use Throwable;

/** Writes only the requested native associations, never a replacement set. */
final class EditCardLabelsHandler extends \OCA\Mcp\Modules\Deck\AbstractHandler {
	public const TOOL = 'deck_edit_card_labels';

	/**
	* @param DeckGatewayInterface $gateway Native Deck boundary.
	* @param LoggerInterface $logger Logs exception classes only.
	* @param CardFormatter $formatter Filters the public card payload.
	* @param Closure(): PlanState $stateFactory Lazy instance-secret fingerprint service.
	*/
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger,
		private CardFormatter $formatter, private Closure $stateFactory) {
		parent::__construct($gateway, $logger);
	}

	/**
	* @param array<string, mixed> $arguments Validated tool arguments.
	* @param string $userId Authenticated caller.
	* @return array<string, mixed> Read-only before/after plan.
	* @throws \Throwable When validation or the native access check fails.
	*/
	public function preview(array $arguments, string $userId): array {
		$changes = CardLabelChanges::parse($arguments);
		$state = $this->gateway->cardLabelState($userId, (int)$arguments['cardId'], CardLabelChanges::requested($changes));
		return $this->plan($state, $changes, $userId);
	}

	/**
	* @param array<string, mixed> $state Fresh editable card and label state.
	* @param array{add: list<int>, remove: list<int>} $changes Normalized request.
	* @param string $userId Caller the fingerprint belongs to.
	* @return array<string, mixed> Confirmation plan without internal foreign label IDs.
	*/
	private function plan(array $state, array $changes, string $userId): array {
		return EditCardLabelsPreview::fromState($state, $changes, $userId, $this->stateFactory);
	}

	/**
	 * {@inheritDoc} The registry owns the no-confirm preview gate.
	 * @param array $arguments validated tool arguments
	 * @param string $userId authenticated caller UID
	 * @return array unchanged result of this operation
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			$changes = CardLabelChanges::parse($arguments);
			$cardId = (int)$arguments['cardId'];
			$requested = CardLabelChanges::requested($changes);
			$state = $this->gateway->cardLabelState($userId, $cardId, $requested);
			$plan = $this->plan($state, $changes, $userId);
			PlanState::require($arguments);
			if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
				$plan['warnings'] = [['message' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::labelPlanChanged()]];
				throw new PlanChanged($plan);
			}
			$shared = $this->confirmShared($arguments, $userId, $state['board']);
			if ($shared !== null) {
				return $shared;
			}
			$delta = CardLabelChanges::delta($state, $changes);
			$pending = ['add' => $delta['add'], 'remove' => $delta['remove']];
			$report = ['added' => [], 'removed' => [], 'unchanged' => $delta['unchanged'], 'warnings' => [],
				'verification' => 'observed-state'];
			foreach (['remove', 'add'] as $direction) {
				foreach ($delta[$direction] as $id) {
					$before = CardLabelChanges::snapshot($state);
					$error = null;
					try {
						$this->gateway->changeCardLabel($userId, $cardId, $id, $direction === 'add', $requested, $before);
					} catch (DeckConflictException $e) {
						// Exclusive pre-write refusal; another actor's change is never attributed to this write.
						if ($report['added'] === [] && $report['removed'] === []) {
							$fresh = $this->preview($arguments, $userId);
							$fresh['warnings'] = [['message' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::labelPlanChanged()]];
							throw new PlanChanged($fresh);
						}
						$observed = $this->observe($userId, $cardId, $requested);
						return $this->partial($report, $pending, $observed, $state);
					} catch (Throwable $e) {
						// Includes pre-write ACL refusals. If the read-back also fails, "unknown" is deliberately
						// conservative: exception type alone cannot prove that a native listener did not persist.
						$error = $e;
					}
					$observed = $this->observe($userId, $cardId, $requested);
					if ($observed === null) {
						return $this->partial($report, $pending, null, $state);
					}
					$assigned = in_array($id, array_column($observed['labels'], 'id'), true);
					if ($assigned !== ($direction === 'add')) {
						if ($error !== null && $report['added'] === [] && $report['removed'] === []
							&& $before === CardLabelChanges::snapshot($observed)) {
							// Same clean-failure contract as AbstractHandler::guardedWrite: nothing changed,
							// so let run() map the original exception to the usual Deck error result.
							throw $error;
						}
						return $this->partial($report, $pending, $observed, $state);
					}
					$report[$direction === 'add' ? 'added' : 'removed'][] = $id;
					$pending[$direction] = array_values(array_diff($pending[$direction], [$id]));
					if ($error !== null) {
						$this->logger->warning('MCP Deck label state observed after failure ({exception})', ['exception' => $error::class]);
						// A concurrent identical assignment can explain this state too; do not claim authorship.
						$report['warnings'][] = \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::labelStateObserved();
					}
					$expected = $before;
					$expected['labels'] = CardLabelChanges::delta($state,
						['add' => $direction === 'add' ? [$id] : [], 'remove' => $direction === 'remove' ? [$id] : []])['after'];
					if ($expected !== CardLabelChanges::snapshot($observed)) {
						return $this->partial($report, $pending, $observed, $state);
					}
					$state = $observed;
				}
			}
			return $this->formatter->card($state['card'], $state['boardId'])
				+ ['labels' => $state['labels'], 'confirmed' => true,
					'changed' => $report['added'] !== [] || $report['removed'] !== []]
				+ $this->progress($report, $pending);
		});
	}

	/**
	* @param string $userId Authenticated caller.
	* @param int $cardId Card just written or refused.
	* @param list<int> $requested IDs whose metadata must remain valid.
	* @return array<string, mixed>|null Fresh state, or null when it cannot be safely read.
	*/
	private function observe(string $userId, int $cardId, array $requested): ?array {
		try {
			return $this->gateway->cardLabelState($userId, $cardId, $requested);
		} catch (Throwable $e) {
			$this->logger->warning('MCP Deck label result unknown ({exception})', ['exception' => $e::class]);
			return null;
		}
	}

	/**
	* @param array<string, mixed> $report Associations whose desired state was observed, without authorship claims.
	* @param array{add: list<int>, remove: list<int>} $pending Unverified requests, including an uncertain write.
	* @return array<string, mixed> Progress present on every success or partial result.
	*/
	private function progress(array $report, array $pending): array {
		return $report + ['applied' => ['add' => $report['added'], 'remove' => $report['removed']], 'pending' => $pending];
	}

	/**
	* @param array<string, mixed> $report Observed progress.
	* @param array{add: list<int>, remove: list<int>} $pending Requests not yet verified.
	* @param array<string, mixed>|null $observed Current readable state, null when unavailable.
	* @param array<string, mixed> $last Last successfully read state; never presented as current.
	* @return array<string, mixed> Partial result with explicit current/last observed labels and pending IDs.
	*/
	private function partial(array $report, array $pending, ?array $observed, array $last): array {
		return ['confirmed' => false, 'partial' => true, 'labels' => $observed['labels'] ?? null,
			'labelsCurrent' => $observed !== null, 'lastObservedLabels' => $last['labels'],
			'message' => $observed === null ? \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::labelsUnconfirmed() : \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::labelsPartial()]
			+ $this->progress($report, $pending);
	}

	/**
	 * {@inheritDoc}
	 * @return string unchanged result of this operation
	 */
	protected function toolName(): string {
		return self::TOOL;
	}
}
