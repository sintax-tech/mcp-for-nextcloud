<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use OCA\Mcp\Modules\Deck\AbstractHandler;
use OCA\Mcp\Modules\Deck\Integration\{DeckGatewayInterface, CardStatusGatewayInterface, CardFormatter};
use OCA\Mcp\Modules\Deck\Failures\DeckConflictException;
use OCA\Mcp\Modules\Deck\Presentation\{DeckRefusalMessages, DeckStatusMessages, DeckWriteMessages};
use OCA\Mcp\Mcp\Confirmation\{PlanState, PlanChanged};
use Psr\Log\LoggerInterface;
use Closure;
use Throwable;

/** Confirms and sets only done, then classifies a fresh native read instead of trusting exceptions. */
final class SetCardDoneHandler extends AbstractHandler {
	public const TOOL = 'deck_set_card_done';
	/**
	 * @param DeckGatewayInterface $gateway Existing filtered card read port.
	 * @param LoggerInterface $logger Safe exception-class logger.
	 * @param Closure():CardStatusGatewayInterface $statusFactory Lazy focused native status port.
	 * @param CardFormatter $formatter Formatter for this invocation's caller.
	 * @param Closure():PlanState $stateFactory Instance-secret signer, resolved lazily.
	 */
	public function __construct(DeckGatewayInterface $gateway, LoggerInterface $logger,
		private Closure $statusFactory, private CardFormatter $formatter, private Closure $stateFactory) {
		parent::__construct($gateway, $logger);
	}
	/** {@inheritDoc} @param array $arguments Validated status intent. @param string $userId Caller. @return array Observed result. */
	public function handle(array $arguments, string $userId): array {
		return $this->run(function () use ($arguments, $userId): array {
			PlanState::require($arguments);
			$status = ($this->statusFactory)();
			$before = $status->state($userId, (int)$arguments['cardId']);
			$plan = CardStatusPlan::of(self::TOOL, $userId, 'done', $arguments, $before, ($this->stateFactory)());
			if (!PlanState::matches($plan['plan_state'], $arguments)) {
				throw new PlanChanged($plan);
			}
			$confirmation = $this->confirmShared($arguments, $userId, $before['board']);
			if ($confirmation !== null) { return $confirmation; }
			if (isset($arguments['lastModified']) && $arguments['lastModified'] !== $before['card']['lastModified']) {
				throw new DeckConflictException(DeckRefusalMessages::errorConflict());
			}
			if (!$plan['changed']) {
				return $this->formatter->card($this->gateway->findCard($userId, (int)$arguments['cardId']))
					+ ['confirmed' => true, 'changed' => false, 'message' => $plan['message'],
						'currentList' => $plan['currentList'], 'doneColumn' => $plan['doneColumn']];
			}
			$error = null;
			try {
				// Final fresh plan check, immediately before the native method; no atomicity is claimed.
				$fresh = $status->state($userId, (int)$arguments['cardId']);
				if ($fresh !== $before) {
					throw new PlanChanged(CardStatusPlan::of(self::TOOL, $userId, 'done', $arguments, $fresh, ($this->stateFactory)()));
				}
				$status->setDone($userId, (int)$arguments['cardId'], $arguments['done']);
			} catch (PlanChanged $e) {
				throw $e;
			} catch (Throwable $e) {
				$error = $e;
				$this->logger->warning('MCP Deck status write raised ({exception})', ['exception' => $e::class]);
			}
			try {
				$after = $status->state($userId, (int)$arguments['cardId']);
				$payload = $this->formatter->card($this->gateway->findCard($userId, (int)$arguments['cardId']));
			} catch (Throwable $e) {
				$this->logger->warning('MCP Deck status read-back unavailable ({exception})', ['exception' => $e::class]);
				return ['confirmed' => false, 'message' => DeckWriteMessages::writeUnconfirmed('deck_read_card')];
			}
			$result = CardStatusResult::of($before, $after, 'done', $arguments['done'], $error);
			return $result + $payload;
		});
	}
	/** {@inheritDoc} @return string Fixed handler identity. */
	protected function toolName(): string { return self::TOOL; }
}
