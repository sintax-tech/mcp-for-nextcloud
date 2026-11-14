<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

/** Prepares the signed deck_update_label and deck_delete_label confirmations without a write. */
final class LabelChangePreview {
	/** @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $context per-call composition and failure boundary */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $context) {
	}

	/**
	 * @param string $tool deck_update_label or deck_delete_label
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> plan with its caller timezone
	 */
	public function plan(string $tool, array $arguments, string $userId): array {
		return $this->context->preview($tool, function () use ($tool, $arguments, $userId): array {
			$state = $this->context->gateway()->boardLabels()->labelState($userId, (int)$arguments['labelId']);
			$after = $tool === UpdateLabelHandler::TOOL ? LabelPlans::updated($arguments, $state) : null;
			return LabelPlans::plan($tool, $state, $after, $userId, fn () => $this->context->planState());
		}, $userId);
	}
}
