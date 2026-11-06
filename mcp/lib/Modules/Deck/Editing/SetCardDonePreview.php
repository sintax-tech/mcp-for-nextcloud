<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use OCA\Mcp\Modules\Deck\DeckExecutionContext;

/** Read-only status plan under the same app gate and state port as the effect. */
final class SetCardDonePreview {
	/** @param DeckExecutionContext $context Lazy caller-bound composition. */
	public function __construct(private DeckExecutionContext $context) {}
	/** @param array<string,mixed> $arguments Intent. @param string $userId Caller. @return array<string,mixed> Signed native-state plan. */
	public function plan(array $arguments, string $userId): array {
		return $this->context->preview('deck_set_card_done', fn (): array => CardStatusPlan::of('deck_set_card_done', $userId, 'done',
			$arguments, $this->context->statusGateway()->state($userId, (int)$arguments['cardId']), $this->context->planState()), $userId);
	}
}
