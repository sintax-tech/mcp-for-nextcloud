<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckWriteMessages;
use Psr\Log\LoggerInterface;

/**
 * Creates the missing labels of a new card on its board, then assigns every requested label to the card.
 *
 * Runs only after the card exists, so nothing here turns into an error that would invite creating the card again: a
 * write that throws is read back (the catalog for a label, the card for an assignment) and counted as done when the
 * state shows it, otherwise listed as failed in one warning. Nothing is undone.
 */
final class CardLabelWriter {
	/**
	 * @param DeckGatewayInterface $gateway Deck access.
	 * @param LoggerInterface $logger Receives exception classes only.
	 */
	public function __construct(private DeckGatewayInterface $gateway, private LoggerInterface $logger) {
	}

	/**
	 * @param string $userId Authenticated caller, owner of the card.
	 * @param int $stackId List of the card, whose board receives new labels.
	 * @param int $cardId Card created in this call.
	 * @param array{boardId: int, assign: list<array>, create: list<array{title: string, color: string}>} $plan Resolved labels.
	 * @return array{labels: list<array>, created: list<array>, warnings: list<string>} Labels confirmed on the card,
	 *     labels confirmed on the board, and warnings.
	 */
	public function apply(string $userId, int $stackId, int $cardId, array $plan): array {
		$labels = $plan['assign'];
		$created = [];
		$failed = [];
		$warnings = [];
		foreach ($plan['create'] as $new) {
			$label = $this->attempt(
				fn (): array => $this->gateway->boardLabels()->createLabel($userId, $plan['boardId'], $new['title'], $new['color']),
				fn (): ?array => LabelInput::matches($this->gateway->boardLabels()->stackLabels($userId, $stackId)['labels'], $new['title'])[0] ?? null,
				$warnings,
			);
			if ($label === null) {
				$failed[] = $new['title'];
				continue;
			}
			$created[] = $label;
			$labels[] = $label;
		}
		$assigned = [];
		foreach ($labels as $label) {
			$done = $this->attempt(
				function () use ($userId, $cardId, $label): bool {
					$this->gateway->boardLabels()->assignCardLabel($userId, $cardId, $label['id']);
					return true;
				},
				fn (): ?bool => in_array($label['id'], array_column($this->gateway->cardLabelState($userId, $cardId, [$label['id']])['labels'], 'id'), true) ? true : null,
				$warnings,
			);
			if ($done === null) {
				$failed[] = $label['title'];
				continue;
			}
			$assigned[] = ['id' => $label['id'], 'title' => $label['title'], 'color' => $label['color']];
		}
		if ($failed !== []) {
			$warnings[] = DeckLabelMessages::cardLabelsFailed(array_values(array_unique($failed)));
		}
		return ['labels' => $assigned, 'created' => $created, 'warnings' => $warnings];
	}

	/**
	 * @param callable(): mixed $write One native write.
	 * @param callable(): mixed $verify Read-back: the written value, or null when the state does not show it.
	 * @param list<string> $warnings Receives the saved-then-failed warning once.
	 * @return mixed The written or observed value, or null when it failed or cannot be confirmed.
	 */
	private function attempt(callable $write, callable $verify, array &$warnings): mixed {
		try {
			return $write();
		} catch (\Throwable $e) {
			$this->logger->warning('MCP Deck label write failed after card creation ({exception})', ['exception' => $e::class]);
			try {
				$saved = $verify();
			} catch (\Throwable) {
				return null;
			}
			if ($saved !== null && !in_array(DeckWriteMessages::writtenThenFailed(), $warnings, true)) {
				$warnings[] = DeckWriteMessages::writtenThenFailed();
			}
			return $saved;
		}
	}
}
