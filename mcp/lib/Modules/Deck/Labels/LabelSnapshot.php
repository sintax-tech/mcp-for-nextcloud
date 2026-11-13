<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Labels;

/** The scalar state of one label a confirmation binds to, compared again right before the native write. */
final class LabelSnapshot {
	/**
	 * @param array<string, mixed> $state Fresh {@see \OCA\Mcp\Modules\Deck\Integration\DeckBoardLabels::labelState()}.
	 * @param bool $withUse True when the number of cards carrying the label is part of what was approved (delete).
	 * @return array<string, mixed> Label, board identity and, for a delete, the card count.
	 */
	public static function of(array $state, bool $withUse): array {
		return ['label' => $state['label'], 'boardId' => $state['boardId'], 'board' => $state['board']]
			+ ($withUse ? ['cards' => $state['cards'], 'cardIds' => $state['cardIds'] ?? []] : []);
	}

	/** Static helper. */
	private function __construct() {
	}
}
